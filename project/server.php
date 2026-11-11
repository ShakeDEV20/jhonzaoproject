<?php
declare(strict_types=1);

// ShopNow demo: PHP backend instances and a small reverse-proxy load balancer.
$role = getenv('ROLE') ?: 'backend';

function json_response(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function state_dir(): string
{
    $dir = getenv('STATE_DIR') ?: '/state';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function locked_state(callable $callback): mixed
{
    $path = state_dir() . '/state.json';
    $lock = fopen(state_dir() . '/state.lock', 'c+');
    flock($lock, LOCK_EX);
    $decoded = json_decode((string) @file_get_contents($path), true);
    $state = is_array($decoded) ? $decoded : [];
    $state += ['algorithm' => getenv('ALGORITHM') ?: 'round_robin', 'cursor' => 0, 'active' => []];
    $result = $callback($state);
    file_put_contents($path, json_encode($state), LOCK_EX);
    flock($lock, LOCK_UN);
    fclose($lock);
    return $result;
}

function backends(): array
{
    $raw = getenv('BACKENDS') ?: 'http://app1:8000,http://app2:8000,http://app3:8000';
    $urls = array_values(array_filter(array_map('trim', explode(',', $raw))));
    $result = [];
    foreach ($urls as $index => $url) {
        $result[] = ['name' => 'app' . ($index + 1), 'url' => rtrim($url, '/')];
    }
    return $result;
}

function read_health_cache(): ?array
{
    $data = json_decode((string) @file_get_contents(state_dir() . '/health.json'), true);
    if (!is_array($data) || !isset($data['checked_at'], $data['backends'])) {
        return null;
    }
    return $data;
}

function refresh_health_cache(): array
{
    $cache = read_health_cache();
    if ($cache !== null && microtime(true) - (float) $cache['checked_at'] < 1.5) {
        return $cache['backends'];
    }

    $lock = fopen(state_dir() . '/health.lock', 'c+');
    flock($lock, LOCK_EX);
    $cache = read_health_cache();
    if ($cache !== null && microtime(true) - (float) $cache['checked_at'] < 1.5) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return $cache['backends'];
    }

    $health = [];
    foreach (backends() as $backend) {
        $context = stream_context_create(['http' => ['timeout' => 0.6, 'ignore_errors' => true]]);
        $body = @file_get_contents($backend['url'] . '/health', false, $context);
        $statusLine = $http_response_header[0] ?? '';
        $health[$backend['name']] = $body !== false && str_contains($statusLine, ' 200 ');
    }
    $data = ['checked_at' => microtime(true), 'backends' => $health];
    $temp = state_dir() . '/health.' . getmypid() . '.tmp';
    file_put_contents($temp, json_encode($data));
    rename($temp, state_dir() . '/health.json');
    flock($lock, LOCK_UN);
    fclose($lock);
    return $health;
}

function mark_unhealthy(string $name): void
{
    $lock = fopen(state_dir() . '/health.lock', 'c+');
    flock($lock, LOCK_EX);
    $cache = read_health_cache() ?? ['backends' => []];
    $cache['backends'][$name] = false;
    $cache['checked_at'] = microtime(true);
    file_put_contents(state_dir() . '/health.json', json_encode($cache), LOCK_EX);
    flock($lock, LOCK_UN);
    fclose($lock);
}

function set_algorithm(string $algorithm): bool
{
    if (!in_array($algorithm, ['round_robin', 'least_connections'], true)) {
        return false;
    }
    locked_state(function (&$state) use ($algorithm): void {
        $state['algorithm'] = $algorithm;
    });
    return true;
}

function select_backend(array $healthy, array $excluded): ?array
{
    return locked_state(function (&$state) use ($healthy, $excluded): ?array {
        $available = array_values(array_filter(backends(), fn($b) =>
            ($healthy[$b['name']] ?? false) && !in_array($b['name'], $excluded, true)
        ));
        if ($available === []) {
            return null;
        }
        foreach ($available as $backend) {
            $state['active'][$backend['name']] ??= 0;
        }
        if ($state['algorithm'] === 'least_connections') {
            usort($available, fn($a, $b) =>
                ($state['active'][$a['name']] <=> $state['active'][$b['name']])
                ?: strcmp($a['name'], $b['name'])
            );
            $selected = $available[0];
        } else {
            $selected = $available[$state['cursor'] % count($available)];
            $state['cursor']++;
        }
        $state['active'][$selected['name']]++;
        return $selected;
    });
}

function release_backend(string $name): void
{
    locked_state(function (&$state) use ($name): void {
        $state['active'][$name] = max(0, ($state['active'][$name] ?? 1) - 1);
    });
}

function load_balancer_status(): array
{
    $health = refresh_health_cache();
    $state = locked_state(function (&$state) { return $state; });
    $result = [];
    foreach (backends() as $backend) {
        $result[] = [
            'name' => $backend['name'],
            'url' => $backend['url'],
            'healthy' => $health[$backend['name']] ?? false,
            'active_requests' => $state['active'][$backend['name']] ?? 0,
        ];
    }
    return ['algorithm' => $state['algorithm'],
        'available_algorithms' => ['round_robin', 'least_connections'],
        'backends' => $result];
}

function proxy_request(): never
{
    $body = file_get_contents('php://input');
    $excluded = [];
    $lastError = 'Nenhuma instancia saudavel disponivel';
    $health = refresh_health_cache();
    while (true) {
        $backend = select_backend($health, $excluded);
        if ($backend === null) {
            json_response(503, ['error' => $lastError, 'balancer' => load_balancer_status()]);
        }
        $name = $backend['name'];
        $excluded[] = $name;
        try {
            $headers = [];
            foreach ($_SERVER as $key => $value) {
                if (str_starts_with($key, 'HTTP_') && !in_array($key, ['HTTP_HOST', 'HTTP_CONNECTION'], true)) {
                    $label = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                    $headers[] = $label . ': ' . $value;
                }
            }
            if (isset($_SERVER['CONTENT_TYPE'])) {
                $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
            }
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            $context = stream_context_create(['http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body === '' ? null : $body,
                'timeout' => 3,
                'ignore_errors' => true,
            ]]);
            $responseBody = @file_get_contents($backend['url'] . $uri, false, $context);
            $responseHeaders = $http_response_header ?? [];
            if ($responseBody === false || $responseHeaders === []) {
                throw new RuntimeException('Conexao recusada ou timeout');
            }
            $statusLine = array_shift($responseHeaders);
            if (preg_match('/\s(\d{3})\s/', (string) $statusLine, $matches)) {
                http_response_code((int) $matches[1]);
            }
            foreach ($responseHeaders as $header) {
                if (stripos($header, 'Transfer-Encoding:') !== 0 && stripos($header, 'Connection:') !== 0) {
                    header($header, false);
                }
            }
            echo $responseBody;
            break;
        } catch (Throwable $error) {
            $lastError = 'Falha ao acessar ' . $name . ': ' . $error->getMessage();
            mark_unhealthy($name);
            $health[$name] = false;
        } finally {
            release_backend($name);
        }
    }
    exit;
}

if ($role === 'backend') {
    $instance = getenv('INSTANCE_ID') ?: 'shopnow-local';
    if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/health') {
        json_response(200, ['status' => 'ok', 'instance' => $instance]);
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo "ShopNow - resposta da instancia\n";
    echo "Instancia: {$instance}\n";
    echo 'Caminho: ' . ($_SERVER['REQUEST_URI'] ?? '/') . "\n";
    echo 'Horario UTC: ' . gmdate('Y-m-d H:i:s') . "\n";
    exit;
}

if ($role !== 'load_balancer') {
    json_response(500, ['error' => 'ROLE deve ser backend ou load_balancer']);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/__lb/status' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    json_response(200, load_balancer_status());
}
if ($path === '/__lb/algorithm' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    parse_str((string) file_get_contents('php://input'), $form);
    $algorithm = $form['algorithm'] ?? '';
    if (!set_algorithm($algorithm)) {
        json_response(400, ['error' => 'Use round_robin ou least_connections']);
    }
    json_response(200, ['algorithm' => $algorithm]);
}
proxy_request();
