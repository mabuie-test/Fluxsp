<?php
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function get_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function starts_with(string $haystack, string $needle): bool {
    if ($needle === '') return true;
    return substr($haystack, 0, strlen($needle)) === $needle;
}

function db(): PDO {
    static $pdo = null;
    global $config;
    if ($pdo instanceof PDO) return $pdo;
    $db = $config['db'];
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset']);
    try {
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        throw new RuntimeException('db_unavailable');
    }
    return $pdo;
}

function ensure_schema(): void {
    static $done = false;
    if ($done) return;
    try {
        $sql = file_get_contents(__DIR__ . '/schema.sql');
        $statements = array_filter(array_map('trim', explode(';', (string)$sql)));
        foreach ($statements as $stmt) {
            if ($stmt === '') continue;
            try {
                db()->exec($stmt);
            } catch (Throwable $e) {
                // Ignora erros de migração repetida/compatibilidade para manter idempotência.
                continue;
            }
        }
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => 'db_unavailable'], 503);
    }
    $done = true;
}

function b64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64url_decode(string $data): string {
    $pad = 4 - (strlen($data) % 4);
    if ($pad < 4) $data .= str_repeat('=', $pad);
    return base64_decode(strtr($data, '-_', '+/')) ?: '';
}

function jwt_sign(array $payload): string {
    global $config;
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $segments = [b64url_encode(json_encode($header)), b64url_encode(json_encode($payload))];
    $sig = hash_hmac('sha256', implode('.', $segments), $config['jwt_secret'], true);
    $segments[] = b64url_encode($sig);
    return implode('.', $segments);
}

function jwt_verify(string $jwt): ?array {
    global $config;
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    $expected = b64url_encode(hash_hmac('sha256', $h . '.' . $p, $config['jwt_secret'], true));
    if (!hash_equals($expected, $s)) return null;
    $payload = json_decode(b64url_decode($p), true);
    if (!is_array($payload)) return null;
    if (isset($payload['exp']) && time() > (int)$payload['exp']) return null;
    return $payload;
}

function auth_user(bool $required = true): ?array {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (starts_with($auth, 'Bearer ')) {
        $token = substr($auth, 7);
        $payload = jwt_verify($token);
        if ($payload) return $payload;
    }
    if ($required) json_response(['error' => 'invalid_token'], 401);
    return null;
}

function is_admin(array $user): bool {
    return ($user['role'] ?? 'user') === 'admin';
}


function json_datetime_from_millis($value): ?string {
    if ($value === null || $value === '') return null;
    if (!is_numeric($value)) return null;
    $seconds = ((float)$value) / 1000;
    return gmdate('Y-m-d H:i:s', (int)$seconds);
}

function normalize_phone_digits(?string $value): string {
    $digits = preg_replace('/\D+/', '', (string)$value);
    if (!is_string($digits)) return '';
    return ltrim($digits, '0');
}

function signed_media_token(array $user, string $fileId, bool $download = false, int $ttlSeconds = 300): string {
    $payload = [
        'id' => (string)($user['id'] ?? ''),
        'role' => $user['role'] ?? 'user',
        'email' => $user['email'] ?? null,
        'fileId' => $fileId,
        'download' => $download,
        'scope' => 'media_access',
        'exp' => time() + max(30, $ttlSeconds),
    ];
    return jwt_sign($payload);
}

function verify_signed_media_token(string $token, string $fileId, bool $download = false): ?array {
    $payload = jwt_verify($token);
    if (!$payload) return null;
    if (($payload['scope'] ?? null) !== 'media_access') return null;
    if (($payload['fileId'] ?? null) !== $fileId) return null;
    if ((bool)($payload['download'] ?? false) !== $download) return null;
    return $payload;
}

function signed_realtime_token(array $user, string $deviceId, int $ttlSeconds = 45): string {
    $cfg = realtime_config();
    $ttl = max(15, (int)($cfg['stream_ttl'] ?? $ttlSeconds));
    $payload = [
        'id' => (string)($user['id'] ?? ''),
        'role' => $user['role'] ?? 'user',
        'email' => $user['email'] ?? null,
        'deviceId' => $deviceId,
        'scope' => 'realtime_stream',
        'exp' => time() + $ttl,
    ];
    return jwt_sign($payload);
}

function verify_signed_realtime_token(string $token, string $deviceId): ?array {
    $payload = jwt_verify($token);
    if (!$payload) return null;
    if (($payload['scope'] ?? null) !== 'realtime_stream') return null;
    if (($payload['deviceId'] ?? null) !== $deviceId) return null;
    return $payload;
}

function record_metric(?string $deviceId, string $metricType, string $metricName, ?string $status = null, $valueMs = null, $valueNum = null, ?array $context = null): void {
    try {
        $st = db()->prepare('INSERT INTO system_metrics(device_id, metric_type, metric_name, status, value_ms, value_num, context_json) VALUES(?,?,?,?,?,?,?)');
        $st->execute([
            $deviceId,
            $metricType,
            $metricName,
            $status,
            $valueMs !== null ? (int)$valueMs : null,
            $valueNum !== null ? $valueNum : null,
            $context ? json_encode($context) : null,
        ]);
    } catch (Throwable $e) {
        // métricas não devem quebrar o fluxo principal
    }
}


function debito_config(): array {
    global $config;
    return $config['debito'] ?? [];
}

function realtime_config(): array {
    global $config;
    return $config['realtime'] ?? [];
}

function publish_realtime_event(string $deviceId, string $event, array $payload = []): void {
    $cfg = realtime_config();
    if (empty($cfg['enabled'])) return;

    try {
        $body = json_encode([
            'deviceId' => $deviceId,
            'event' => $event,
            'payload' => $payload,
        ]);
        if ($body === false) return;

        $ins = db()->prepare('INSERT INTO realtime_events(device_id, event_name, payload_json) VALUES(?,?,?)');
        $ins->execute([$deviceId, $event, $body]);

        if (random_int(1, 40) === 1) {
            db()->exec('DELETE FROM realtime_events WHERE created_at < (NOW() - INTERVAL 1 DAY)');
        }
    } catch (Throwable $e) {
        // realtime não deve quebrar o fluxo principal
    }
}

function debito_is_configured(): bool {
    $cfg = debito_config();
    return !empty($cfg['base_url']) && !empty($cfg['api_token']) && !empty($cfg['wallet_id']);
}

function debito_request(string $method, string $path, ?array $payload = null): array {
    $cfg = debito_config();
    if (!debito_is_configured()) {
        throw new RuntimeException('debito_not_configured');
    }

    $url = rtrim((string)$cfg['base_url'], '/') . $path;
    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $cfg['api_token'],
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 90,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('debito_request_failed:' . $error);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    return [
        'status' => $status,
        'ok' => $status >= 200 && $status < 300,
        'body' => is_array($decoded) ? $decoded : ['raw' => $raw],
        'raw' => $raw,
    ];
}
