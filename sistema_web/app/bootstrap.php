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
    if (str_starts_with($auth, 'Bearer ')) {
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
