<?php
require __DIR__ . '/../app/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Secret');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    exit;
}
header('Access-Control-Allow-Origin: *');

function route_match(string $pattern, string $uri): ?array {
    $regex = '#^' . preg_replace('#:([a-zA-Z_]+)#', '(?P<$1>[^/]+)', $pattern) . '$#';
    if (!preg_match($regex, $uri, $matches)) return null;
    return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
}

function safe_json_decode(?string $json): ?array {
    if (!$json) return null;
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
}

function require_admin(): array {
    $user = auth_user();
    if (!is_admin($user)) json_response(['error' => 'forbidden'], 403);
    return $user;
}

function find_device(string $deviceId): ?array {
    $st = db()->prepare('SELECT * FROM devices WHERE device_id = ? LIMIT 1');
    $st->execute([$deviceId]);
    $d = $st->fetch();
    return $d ?: null;
}

function can_access_device(array $user, array $device): bool {
    if (is_admin($user)) return true;
    return !empty($device['owner_user_id']) && (string) $device['owner_user_id'] === (string) ($user['id'] ?? '');
}

function normalize_device(array $row): array {
    $row['deviceId'] = $row['device_id'] ?? null;
    $row['owner'] = $row['owner_user_id'] ?? null;
    $row['lastSeen'] = $row['last_seen'] ?? null;
    $row['consentAccepted'] = isset($row['consent_accepted']) ? (bool) $row['consent_accepted'] : null;
    $row['consentTs'] = $row['consent_ts'] ?? null;
    $row['consentTextVersion'] = $row['consent_text_version'] ?? null;
    $row['createdAt'] = $row['created_at'] ?? null;
    return $row;
}

function normalize_payment(array $row): array {
    $row['_id'] = $row['id'] ?? null;
    $row['createdAt'] = $row['created_at'] ?? null;
    $row['processedAt'] = $row['processed_at'] ?? null;
    $row['mediaFileId'] = $row['media_file_id'] ?? null;
    if (isset($row['email']) || isset($row['name'])) {
        $row['user'] = [
            'email' => $row['email'] ?? null,
            'name' => $row['name'] ?? null,
        ];
    }
    return $row;
}

function expire_support_sessions(): void {
    db()->exec("UPDATE support_sessions SET status = 'expired' WHERE status = 'pending' AND response_deadline_at < NOW()");
    db()->exec("UPDATE support_sessions SET status = 'expired', stopped_at = COALESCE(stopped_at, NOW()) WHERE status = 'approved' AND session_expires_at IS NOT NULL AND session_expires_at < NOW()");
}

function find_support_session(string $sessionId): ?array {
    expire_support_sessions();
    $st = db()->prepare('SELECT * FROM support_sessions WHERE session_id = ? LIMIT 1');
    $st->execute([$sessionId]);
    $row = $st->fetch();
    return $row ?: null;
}

function log_support_session_event(string $sessionId, string $eventType, ?string $actorUserId = null, ?array $metadata = null): void {
    $st = db()->prepare('INSERT INTO support_session_events(session_id, event_type, actor_user_id, metadata) VALUES(?,?,?,?)');
    $st->execute([
        $sessionId,
        $eventType,
        $actorUserId !== null && $actorUserId !== '' ? $actorUserId : null,
        $metadata ? json_encode($metadata) : null,
    ]);
}

function normalize_support_session_event(array $row): array {
    $row['sessionId'] = $row['session_id'] ?? null;
    $row['eventType'] = $row['event_type'] ?? null;
    $row['actorUserId'] = $row['actor_user_id'] ?? null;
    $row['createdAt'] = $row['created_at'] ?? null;
    $row['metadata'] = safe_json_decode($row['metadata'] ?? null) ?? [];
    return $row;
}

function normalize_support_session(array $row): array {
    $row['sessionId'] = $row['session_id'] ?? null;
    $row['deviceId'] = $row['device_id'] ?? null;
    $row['requestType'] = $row['request_type'] ?? null;
    $row['requestedByUserId'] = $row['requested_by_user_id'] ?? null;
    $row['approvedByUserId'] = $row['approved_by_user_id'] ?? null;
    $row['requestedAt'] = $row['requested_at'] ?? null;
    $row['responseDeadlineAt'] = $row['response_deadline_at'] ?? null;
    $row['respondedAt'] = $row['responded_at'] ?? null;
    $row['sessionExpiresAt'] = $row['session_expires_at'] ?? null;
    $row['stopRequestedAt'] = $row['stop_requested_at'] ?? null;
    $row['stoppedAt'] = $row['stopped_at'] ?? null;
    return $row;
}

if (!str_starts_with($uri, '/api')) {
    $target = $uri === '/' ? '/index.html' : $uri;
    $file = realpath(__DIR__ . $target);
    $base = realpath(__DIR__);

    if ($file && $base && str_starts_with($file, $base) && is_file($file)) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $types = [
            'html' => 'text/html; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
        ];
        if (isset($types[$ext])) header('Content-Type: ' . $types[$ext]);
        readfile($file);
        exit;
    }

    json_response(['error' => 'not_found'], 404);
}

try {
    ensure_schema();
    $body = get_json_body();

    if ($method === 'GET' && $uri === '/api/health') {
        json_response(['ok' => true, 'service' => 'sistema_web', 'db' => 'up']);
    }

    // Auth
    if ($method === 'POST' && $uri === '/api/auth/register') {
        $email = trim((string)($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');
        if ($email === '' || $password === '') json_response(['error' => 'missing_fields'], 400);

        $st = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        if ($st->fetch()) json_response(['error' => 'exists'], 400);

        $ins = db()->prepare('INSERT INTO users(email, password_hash, name) VALUES(?,?,?)');
        $ins->execute([$email, password_hash($password, PASSWORD_BCRYPT), $body['name'] ?? null]);
        json_response(['ok' => true]);
    }

    if ($method === 'POST' && $uri === '/api/auth/login') {
        $email = trim((string)($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');
        if ($email === '' || $password === '') json_response(['error' => 'missing_fields'], 400);

        $st = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch();

        if (!$u || !password_verify($password, $u['password_hash'])) json_response(['error' => 'invalid_credentials'], 401);

        $payload = [
            'id' => (string) $u['id'],
            'role' => $u['role'],
            'email' => $u['email'],
            'exp' => time() + (60 * 60 * 24 * 30),
        ];
        json_response([
            'token' => jwt_sign($payload),
            'userId' => (string) $u['id'],
            'role' => $u['role'],
            'active' => (bool) $u['active'],
        ]);
    }

    if ($method === 'GET' && $uri === '/api/auth/me') {
        $user = auth_user();
        $st = db()->prepare('SELECT id, email, name, role, active, created_at FROM users WHERE id = ? LIMIT 1');
        $st->execute([$user['id']]);
        $u = $st->fetch();
        if (!$u) json_response(['ok' => false, 'error' => 'not_found'], 404);
        $u['active'] = (bool) $u['active'];
        json_response(['ok' => true, 'user' => $u]);
    }

    if ($method === 'POST' && $uri === '/api/auth/register-admin') {
        global $config;
        $allowed = false;
        $secret = (string)($_SERVER['HTTP_X_ADMIN_SECRET'] ?? ($body['adminSecret'] ?? ''));

        if (!empty($config['admin_registration_secret']) && hash_equals($config['admin_registration_secret'], $secret)) {
            $allowed = true;
        }

        if (!$allowed) {
            $caller = auth_user(false);
            if ($caller && is_admin($caller)) $allowed = true;
        }

        if (!$allowed) json_response(['ok' => false, 'error' => 'forbidden'], 403);

        $email = trim((string)($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');
        if ($email === '' || $password === '') json_response(['ok' => false, 'error' => 'missing_fields'], 400);

        $st = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        if ($st->fetch()) json_response(['ok' => false, 'error' => 'exists'], 400);

        $ins = db()->prepare('INSERT INTO users(email, password_hash, name, role, active) VALUES(?,?,?,?,1)');
        $ins->execute([$email, password_hash($password, PASSWORD_BCRYPT), $body['name'] ?? '', 'admin']);

        json_response(['ok' => true, 'userId' => (string) db()->lastInsertId(), 'email' => $email]);
    }


    if ($method === 'POST' && $uri === '/api/auth/forgot-password') {
        $email = trim((string)($body['email'] ?? ''));
        if ($email !== '') {
            $st = db()->prepare('SELECT id, email FROM users WHERE email = ? LIMIT 1');
            $st->execute([$email]);
            $u = $st->fetch();
            if ($u) {
                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = date('Y-m-d H:i:s', time() + 3600);

                $ins = db()->prepare('INSERT INTO password_resets(user_id, token_hash, expires_at) VALUES(?,?,?)');
                $ins->execute([$u['id'], $tokenHash, $expiresAt]);

                $baseUrl = rtrim((string)(getenv('APP_BASE_URL') ?: ''), '/');
                if ($baseUrl === '') {
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $baseUrl = $scheme . '://' . $host;
                }

                $resetLink = $baseUrl . '/reset-password.html?token=' . urlencode($rawToken);
                $html = '<p>Recebemos um pedido para recuperar sua senha.</p>'
                    . '<p><a href="' . htmlspecialchars($resetLink, ENT_QUOTES) . '">Clique aqui para redefinir sua senha</a></p>'
                    . '<p>Se você não solicitou, ignore este email.</p>';

                send_mail($u['email'], 'Recuperação de senha', $html);
            }
        }

        json_response(['ok' => true, 'message' => 'Se o email existir, enviaremos instruções.']);
    }

    if ($method === 'POST' && $uri === '/api/auth/reset-password') {
        $token = (string)($body['token'] ?? '');
        $newPassword = (string)($body['password'] ?? '');
        if ($token === '' || $newPassword === '') json_response(['ok' => false, 'error' => 'missing_fields'], 400);
        if (strlen($newPassword) < 6) json_response(['ok' => false, 'error' => 'weak_password'], 400);

        $tokenHash = hash('sha256', $token);
        $st = db()->prepare('SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1');
        $st->execute([$tokenHash]);
        $row = $st->fetch();
        if (!$row) json_response(['ok' => false, 'error' => 'invalid_or_expired_token'], 400);

        db()->beginTransaction();
        try {
            $upUser = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $upUser->execute([password_hash($newPassword, PASSWORD_BCRYPT), $row['user_id']]);

            $upReset = db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?');
            $upReset->execute([$row['id']]);

            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }

        json_response(['ok' => true]);
    }

    // Devices
    if ($method === 'GET' && $uri === '/api/devices/public') {
        $rows = array_map('normalize_device', db()->query('SELECT * FROM devices ORDER BY last_seen DESC')->fetchAll());
        json_response(['ok' => true, 'devices' => $rows]);
    }

    if ($method === 'GET' && $uri === '/api/devices') {
        require_admin();
        $rows = array_map('normalize_device', db()->query('SELECT * FROM devices ORDER BY last_seen DESC')->fetchAll());
        json_response(['ok' => true, 'devices' => $rows]);
    }

    if ($method === 'GET' && $uri === '/api/devices/my') {
        $u = auth_user();
        $st = db()->prepare('SELECT * FROM devices WHERE owner_user_id = ? ORDER BY last_seen DESC');
        $st->execute([$u['id']]);
        json_response(['ok' => true, 'devices' => array_map('normalize_device', $st->fetchAll())]);
    }

    if ($method === 'POST' && $uri === '/api/devices/auto-assign') {
        $u = auth_user();
        $deviceId = trim((string)($body['deviceId'] ?? ''));
        if ($deviceId === '') json_response(['ok' => false, 'error' => 'missing_device'], 400);

        $d = find_device($deviceId);
        if ($d) {
            if (!empty($d['owner_user_id']) && (string)$d['owner_user_id'] !== (string)$u['id']) {
                json_response(['ok' => false, 'error' => 'already_claimed'], 403);
            }
            $up = db()->prepare('UPDATE devices SET owner_user_id = ?, last_seen = COALESCE(last_seen, NOW()) WHERE device_id = ?');
            $up->execute([$u['id'], $deviceId]);
        } else {
            $ins = db()->prepare('INSERT INTO devices(device_id, owner_user_id, last_seen) VALUES(?,?,NOW())');
            $ins->execute([$deviceId, $u['id']]);
        }

        $device = find_device($deviceId);
        json_response(['ok' => true, 'device' => $device ? normalize_device($device) : null]);
    }

    if ($method === 'GET' && ($m = route_match('/api/devices/:deviceId', $uri))) {
        $u = auth_user();
        $d = find_device($m['deviceId']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['error' => 'forbidden'], 403);
        json_response(['ok' => true, 'device' => normalize_device($d)]);
    }

    if ($method === 'POST' && ($m = route_match('/api/devices/:deviceId/claim', $uri))) {
        $u = auth_user();
        $d = find_device($m['deviceId']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);

        if (!empty($d['owner_user_id']) && (string)$d['owner_user_id'] !== (string)$u['id']) {
            json_response(['ok' => false, 'error' => 'already_claimed'], 403);
        }

        $up = db()->prepare('UPDATE devices SET owner_user_id = ? WHERE device_id = ?');
        $up->execute([$u['id'], $m['deviceId']]);
        json_response(['ok' => true, 'deviceId' => $m['deviceId'], 'owner' => (string) $u['id']]);
    }

    // Support sessions
    if ($method === 'POST' && $uri === '/api/support-sessions/request') {
        $u = auth_user();
        $deviceId = trim((string)($body['deviceId'] ?? ''));
        $requestType = (string)($body['requestType'] ?? '');
        $note = trim((string)($body['note'] ?? ''));
        $responseTtl = (int)($body['responseTtlSeconds'] ?? 120);
        $sessionTtl = (int)($body['sessionTtlSeconds'] ?? 600);

        if ($deviceId === '' || !in_array($requestType, ['screen', 'ambient_audio'], true)) {
            json_response(['ok' => false, 'error' => 'invalid_request'], 400);
        }

        $d = find_device($deviceId);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['ok' => false, 'error' => 'forbidden'], 403);

        expire_support_sessions();
        $open = db()->prepare("SELECT session_id FROM support_sessions WHERE device_id = ? AND status IN ('pending','approved') ORDER BY requested_at DESC LIMIT 1");
        $open->execute([$deviceId]);
        $existing = $open->fetch();
        if ($existing) json_response(['ok' => false, 'error' => 'session_already_open', 'sessionId' => $existing['session_id']], 409);

        $responseTtl = max(30, min($responseTtl, 600));
        $sessionTtl = max(60, min($sessionTtl, 3600));
        $sessionId = bin2hex(random_bytes(16));
        $deadlineAt = date('Y-m-d H:i:s', time() + $responseTtl);

        $ins = db()->prepare('INSERT INTO support_sessions(session_id, device_id, request_type, requested_by_user_id, note, response_deadline_at, session_expires_at) VALUES(?,?,?,?,?,?,?)');
        $ins->execute([$sessionId, $deviceId, $requestType, $u['id'], $note !== '' ? $note : null, $deadlineAt, date('Y-m-d H:i:s', time() + $sessionTtl)]);
        log_support_session_event($sessionId, 'requested', (string)$u['id'], [
            'requestType' => $requestType,
            'responseTtlSeconds' => $responseTtl,
            'sessionTtlSeconds' => $sessionTtl,
            'note' => $note,
        ]);

        $session = find_support_session($sessionId);
        json_response(['ok' => true, 'session' => $session ? normalize_support_session($session) : null]);
    }

    if ($method === 'GET' && ($m = route_match('/api/support-sessions/device/:deviceId/pending', $uri))) {
        $u = auth_user();
        $d = find_device($m['deviceId']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['ok' => false, 'error' => 'forbidden'], 403);

        expire_support_sessions();
        $st = db()->prepare("SELECT * FROM support_sessions WHERE device_id = ? AND status = 'pending' ORDER BY requested_at ASC LIMIT 1");
        $st->execute([$m['deviceId']]);
        $session = $st->fetch();
        json_response(['ok' => true, 'session' => $session ? normalize_support_session($session) : null]);
    }

    if ($method === 'GET' && ($m = route_match('/api/support-sessions/device/:deviceId/active', $uri))) {
        $u = auth_user();
        $d = find_device($m['deviceId']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['ok' => false, 'error' => 'forbidden'], 403);

        expire_support_sessions();
        $st = db()->prepare("SELECT * FROM support_sessions WHERE device_id = ? AND status = 'approved' ORDER BY responded_at DESC LIMIT 1");
        $st->execute([$m['deviceId']]);
        $session = $st->fetch();
        json_response(['ok' => true, 'session' => $session ? normalize_support_session($session) : null]);
    }

    if ($method === 'GET' && ($m = route_match('/api/support-sessions/device/:deviceId/list', $uri))) {
        $u = auth_user();
        $d = find_device($m['deviceId']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['ok' => false, 'error' => 'forbidden'], 403);

        expire_support_sessions();
        $st = db()->prepare('SELECT * FROM support_sessions WHERE device_id = ? ORDER BY requested_at DESC LIMIT 20');
        $st->execute([$m['deviceId']]);
        json_response(['ok' => true, 'sessions' => array_map('normalize_support_session', $st->fetchAll())]);
    }

    if ($method === 'POST' && ($m = route_match('/api/support-sessions/:sessionId/respond', $uri))) {
        $u = auth_user();
        $session = find_support_session($m['sessionId']);
        if (!$session) json_response(['ok' => false, 'error' => 'not_found'], 404);

        $d = find_device($session['device_id']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['ok' => false, 'error' => 'forbidden'], 403);
        if ($session['status'] !== 'pending') json_response(['ok' => false, 'error' => 'invalid_status'], 409);

        $action = (string)($body['action'] ?? '');
        if (!in_array($action, ['approve', 'reject'], true)) json_response(['ok' => false, 'error' => 'invalid_action'], 400);

        if ($action === 'approve') {
            $ttl = max(60, min((int)($body['sessionTtlSeconds'] ?? 600), 3600));
            $up = db()->prepare("UPDATE support_sessions SET status = 'approved', approved_by_user_id = ?, responded_at = NOW(), session_expires_at = ? WHERE session_id = ?");
            $up->execute([$u['id'], date('Y-m-d H:i:s', time() + $ttl), $m['sessionId']]);
            log_support_session_event($m['sessionId'], 'approved', (string)$u['id'], ['sessionTtlSeconds' => $ttl]);
        } else {
            $up = db()->prepare("UPDATE support_sessions SET status = 'rejected', responded_at = NOW(), stopped_at = NOW() WHERE session_id = ?");
            $up->execute([$m['sessionId']]);
            log_support_session_event($m['sessionId'], 'rejected', (string)$u['id']);
        }

        $updated = find_support_session($m['sessionId']);
        json_response(['ok' => true, 'session' => $updated ? normalize_support_session($updated) : null]);
    }

    if ($method === 'POST' && ($m = route_match('/api/support-sessions/:sessionId/stop', $uri))) {
        $u = auth_user();
        $session = find_support_session($m['sessionId']);
        if (!$session) json_response(['ok' => false, 'error' => 'not_found'], 404);

        $d = find_device($session['device_id']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['ok' => false, 'error' => 'forbidden'], 403);
        if (!in_array($session['status'], ['pending', 'approved'], true)) json_response(['ok' => false, 'error' => 'invalid_status'], 409);

        $status = $session['status'] === 'pending' ? 'cancelled' : 'stopped';
        $up = db()->prepare('UPDATE support_sessions SET status = ?, stop_requested_at = NOW(), stopped_at = NOW() WHERE session_id = ?');
        $up->execute([$status, $m['sessionId']]);
        log_support_session_event($m['sessionId'], $status === 'cancelled' ? 'cancelled' : 'stopped', (string)$u['id']);

        $updated = find_support_session($m['sessionId']);
        json_response(['ok' => true, 'session' => $updated ? normalize_support_session($updated) : null]);
    }

    if ($method === 'POST' && ($m = route_match('/api/support-sessions/:sessionId/event', $uri))) {
        $u = auth_user();
        $session = find_support_session($m['sessionId']);
        if (!$session) json_response(['ok' => false, 'error' => 'not_found'], 404);

        $d = find_device($session['device_id']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['ok' => false, 'error' => 'forbidden'], 403);

        $eventType = trim((string)($body['eventType'] ?? ''));
        if ($eventType === '') json_response(['ok' => false, 'error' => 'missing_event_type'], 400);

        $metadata = isset($body['metadata']) && is_array($body['metadata']) ? $body['metadata'] : [];
        log_support_session_event($m['sessionId'], $eventType, (string)$u['id'], $metadata);
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && ($m = route_match('/api/support-sessions/:sessionId/events', $uri))) {
        $u = auth_user();
        $session = find_support_session($m['sessionId']);
        if (!$session) json_response(['ok' => false, 'error' => 'not_found'], 404);

        $d = find_device($session['device_id']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['ok' => false, 'error' => 'forbidden'], 403);

        $st = db()->prepare('SELECT * FROM support_session_events WHERE session_id = ? ORDER BY created_at DESC LIMIT 50');
        $st->execute([$m['sessionId']]);
        json_response(['ok' => true, 'events' => array_map('normalize_support_session_event', $st->fetchAll())]);
    }

    // Telemetry
    if ($method === 'POST' && ($m = route_match('/api/telemetry/:deviceId', $uri))) {
        $deviceId = $m['deviceId'];
        if ($deviceId === '') json_response(['ok' => false, 'error' => 'missing_device'], 400);

        $payload = $body;
        $ts = date('Y-m-d H:i:s');
        $ins = db()->prepare('INSERT INTO telemetry(device_id, payload, ts) VALUES(?,?,?)');
        $ins->execute([$deviceId, json_encode($payload), $ts]);

        $up = db()->prepare('INSERT INTO devices(device_id, last_seen) VALUES(?,?) ON DUPLICATE KEY UPDATE last_seen=VALUES(last_seen)');
        $up->execute([$deviceId, $ts]);

        json_response(['ok' => true, 'id' => (string) db()->lastInsertId()]);
    }

    if ($method === 'GET' && ($m = route_match('/api/telemetry/:deviceId/history', $uri))) {
        $u = auth_user();
        $d = find_device($m['deviceId']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['error' => 'forbidden'], 403);

        $st = db()->prepare('SELECT * FROM telemetry WHERE device_id = ? ORDER BY ts DESC LIMIT 100');
        $st->execute([$m['deviceId']]);
        $items = $st->fetchAll();
        foreach ($items as &$r) $r['payload'] = safe_json_decode($r['payload']) ?? [];
        json_response(['ok' => true, 'total' => count($items), 'items' => $items]);
    }

    if ($method === 'GET' && ($m = route_match('/api/telemetry/:deviceId/items', $uri))) {
        $u = auth_user();
        $d = find_device($m['deviceId']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['error' => 'forbidden'], 403);

        $type = $_GET['type'] ?? null;
        $st = db()->prepare('SELECT * FROM telemetry WHERE device_id = ? ORDER BY ts DESC LIMIT 500');
        $st->execute([$m['deviceId']]);

        $items = [];
        foreach ($st->fetchAll() as $r) {
            $r['payload'] = safe_json_decode($r['payload']) ?? [];
            if (!$type || (($r['payload']['type'] ?? null) === $type)) $items[] = $r;
        }

        json_response(['ok' => true, 'total' => count($items), 'items' => $items]);
    }

    // Payments
    if ($method === 'POST' && $uri === '/api/payments') {
        $u = auth_user();
        $ins = db()->prepare('INSERT INTO payments(user_id, amount, method, note, media_file_id, status) VALUES(?,?,?,?,?,"pending")');
        $ins->execute([$u['id'], $body['amount'] ?? null, $body['method'] ?? null, $body['note'] ?? null, $body['mediaFileId'] ?? null]);
        json_response(['ok' => true, 'id' => (string) db()->lastInsertId()]);
    }

    if ($method === 'GET' && $uri === '/api/payments') {
        require_admin();
        $rows = array_map('normalize_payment', db()->query('SELECT p.*, u.email, u.name FROM payments p JOIN users u ON u.id = p.user_id ORDER BY p.created_at DESC')->fetchAll());
        json_response(['ok' => true, 'payments' => $rows]);
    }

    if ($method === 'GET' && $uri === '/api/payments/mine') {
        $u = auth_user();
        $st = db()->prepare('SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC');
        $st->execute([$u['id']]);
        json_response(['ok' => true, 'payments' => array_map('normalize_payment', $st->fetchAll())]);
    }

    if ($method === 'POST' && ($m = route_match('/api/payments/:id/process', $uri))) {
        $u = require_admin();
        $action = (string)($body['action'] ?? '');
        if (!in_array($action, ['approve', 'reject'], true)) json_response(['ok' => false, 'error' => 'invalid_action'], 400);

        $status = $action === 'approve' ? 'completed' : 'rejected';

        db()->beginTransaction();
        try {
            $up = db()->prepare('UPDATE payments SET status=?, processed_at=?, processed_by=? WHERE id=?');
            $up->execute([$status, date('Y-m-d H:i:s'), $u['id'], $m['id']]);

            if ($up->rowCount() === 0) {
                db()->rollBack();
                json_response(['ok' => false, 'error' => 'not_found'], 404);
            }

            if ($action === 'approve') {
                $q = db()->prepare('UPDATE users u JOIN payments p ON p.user_id = u.id SET u.active=1 WHERE p.id=?');
                $q->execute([$m['id']]);
            }

            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }

        json_response(['ok' => true]);
    }

    // Media
    if ($method === 'GET' && ($m = route_match('/api/media/list/:deviceId', $uri))) {
        $u = auth_user();
        $d = find_device($m['deviceId']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['error' => 'forbidden'], 403);

        $st = db()->prepare('SELECT file_id as fileId, filename, content_type as contentType, upload_date as uploadDate, checksum, device_id as deviceId FROM media WHERE device_id=? ORDER BY upload_date DESC');
        $st->execute([$m['deviceId']]);
        $files = array_map(
            fn($r) => [
                'fileId' => $r['fileId'],
                'filename' => $r['filename'],
                'contentType' => $r['contentType'],
                'uploadDate' => $r['uploadDate'],
                'metadata' => ['checksum' => $r['checksum'], 'deviceId' => $r['deviceId']],
            ],
            $st->fetchAll()
        );
        json_response(['ok' => true, 'files' => $files]);
    }

    if ($method === 'POST' && $uri === '/api/media/checksum') {
        auth_user();
        $checksum = $body['checksum'] ?? null;
        if (!$checksum) json_response(['ok' => false, 'error' => 'missing_checksum'], 400);
        $st = db()->prepare('SELECT file_id FROM media WHERE checksum = ? LIMIT 1');
        $st->execute([$checksum]);
        $f = $st->fetch();
        json_response(['ok' => true, 'exists' => (bool)$f, 'fileId' => $f['file_id'] ?? null]);
    }

    if ($method === 'POST' && ($m = route_match('/api/media/:deviceId/upload', $uri))) {
        $u = auth_user();
        $d = find_device($m['deviceId']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['error' => 'forbidden'], 403);

        if (!isset($_FILES['media']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) {
            json_response(['ok' => false, 'error' => 'no_file'], 400);
        }

        $tmp = $_FILES['media']['tmp_name'];
        $checksum = hash_file('sha256', $tmp);

        $st = db()->prepare('SELECT file_id FROM media WHERE checksum = ? LIMIT 1');
        $st->execute([$checksum]);
        $existing = $st->fetch();
        if ($existing) json_response(['ok' => true, 'exists' => true, 'fileId' => $existing['file_id']]);

        $fileId = bin2hex(random_bytes(16));
        $safeName = $fileId . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['media']['name']));

        global $config;
        if (!is_dir($config['media_dir'])) mkdir($config['media_dir'], 0775, true);

        $dest = rtrim($config['media_dir'], '/') . '/' . $safeName;
        if (!move_uploaded_file($tmp, $dest)) json_response(['ok' => false, 'error' => 'upload_failed'], 500);

        $ins = db()->prepare('INSERT INTO media(file_id, device_id, filename, content_type, checksum, storage_path) VALUES(?,?,?,?,?,?)');
        $ins->execute([
            $fileId,
            $m['deviceId'],
            $_FILES['media']['name'],
            $_FILES['media']['type'] ?: 'application/octet-stream',
            $checksum,
            $safeName,
        ]);

        json_response(['ok' => true, 'fileId' => $fileId, 'checksum' => $checksum]);
    }

    if ($method === 'GET' && ($m = route_match('/api/media/download/:fileId', $uri))) {
        $u = auth_user();
        $st = db()->prepare('SELECT * FROM media WHERE file_id = ? LIMIT 1');
        $st->execute([$m['fileId']]);
        $f = $st->fetch();
        if (!$f) json_response(['ok' => false, 'error' => 'not_found'], 404);

        $d = find_device($f['device_id']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['ok' => false, 'error' => 'forbidden'], 403);

        global $config;
        $path = rtrim($config['media_dir'], '/') . '/' . $f['storage_path'];
        if (!is_file($path)) json_response(['ok' => false, 'error' => 'not_found'], 404);

        header('Content-Type: ' . ($f['content_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . basename($f['filename']) . '"');
        readfile($path);
        exit;
    }

    json_response(['error' => 'not_found'], 404);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'db_unavailable') json_response(['ok' => false, 'error' => 'db_unavailable'], 503);
    json_response(['ok' => false, 'error' => 'server_error'], 500);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'server_error'], 500);
}
