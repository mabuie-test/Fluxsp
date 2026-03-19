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

function device_to_api(array $d): array {
    return [
        'id' => (string)($d['id'] ?? ''),
        'deviceId' => $d['device_id'] ?? null,
        'ownerUserId' => isset($d['owner_user_id']) ? (string)$d['owner_user_id'] : null,
        'name' => $d['name'] ?? null,
        'imei' => $d['imei'] ?? null,
        'model' => $d['model'] ?? null,
        'networkType' => $d['network_type'] ?? null,
        'batteryLevel' => isset($d['battery_level']) ? (int)$d['battery_level'] : null,
        'carrier' => $d['carrier'] ?? null,
        'signalLevel' => isset($d['signal_level']) ? (int)$d['signal_level'] : null,
        'isOnline' => !empty($d['is_online']),
        'lastOnlineAt' => $d['last_online_at'] ?? null,
        'lastSeen' => $d['last_seen'] ?? null,
        'subscriptionStatus' => $d['subscription_status'] ?? 'inactive',
        'subscriptionUntil' => $d['subscription_until'] ?? null,
        'createdAt' => $d['created_at'] ?? null,
    ];
}

function has_active_subscription(array $device): bool {
    if (($device['subscription_status'] ?? '') !== 'active') return false;
    $until = $device['subscription_until'] ?? null;
    if (!$until) return false;
    return strtotime((string)$until) >= time();
}

function require_device_subscription(array $user, array $device): void {
    if (is_admin($user)) return;
    if (has_active_subscription($device)) return;
    json_response([
        'ok' => false,
        'error' => 'subscription_required',
        'message' => 'Subscrição mensal ativa obrigatória para aceder aos dados do aparelho.',
        'priceMzn' => 800,
    ], 402);
}

function grant_device_subscription(string $deviceId, int $days = 30): void {
    $days = max(1, $days);
    $st = db()->prepare('UPDATE devices SET subscription_status="active", subscription_until = DATE_ADD(COALESCE(subscription_until, NOW()), INTERVAL ? DAY) WHERE device_id = ?');
    $st->execute([$days, $deviceId]);
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
        $rows = db()->query('SELECT * FROM devices ORDER BY last_seen DESC')->fetchAll();
        json_response(['ok' => true, 'devices' => array_map('device_to_api', $rows)]);
    }

    if ($method === 'GET' && $uri === '/api/devices') {
        require_admin();
        $rows = db()->query('SELECT * FROM devices ORDER BY last_seen DESC')->fetchAll();
        json_response(['ok' => true, 'devices' => array_map('device_to_api', $rows)]);
    }

    if ($method === 'GET' && $uri === '/api/devices/my') {
        $u = auth_user();
        $st = db()->prepare('SELECT * FROM devices WHERE owner_user_id = ? ORDER BY last_seen DESC');
        $st->execute([$u['id']]);
        json_response(['ok' => true, 'devices' => array_map('device_to_api', $st->fetchAll())]);
    }

    if ($method === 'POST' && $uri === '/api/devices/auto-assign') {
        $u = auth_user();
        $imei = trim((string)($body['imei'] ?? ''));
        $model = trim((string)($body['model'] ?? ''));
        $deviceId = trim((string)($body['deviceId'] ?? ''));
        if ($imei === '') json_response(['ok' => false, 'error' => 'missing_imei'], 400);
        if ($deviceId === '') $deviceId = 'imei-' . substr(hash('sha256', $imei), 0, 24);

        $st = db()->prepare('SELECT * FROM devices WHERE imei = ? LIMIT 1');
        $st->execute([$imei]);
        $existing = $st->fetch();
        if ($existing) {
            if (!empty($existing['owner_user_id']) && (string)$existing['owner_user_id'] !== (string)$u['id']) {
                json_response(['ok' => false, 'error' => 'device_belongs_to_another_user'], 403);
            }
            $up = db()->prepare('UPDATE devices SET owner_user_id=?, model=COALESCE(NULLIF(?, ""), model), last_seen=NOW() WHERE id=?');
            $up->execute([$u['id'], $model, $existing['id']]);
            $existing = find_device($existing['device_id']);
            json_response(['ok' => true, 'assigned' => false, 'device' => device_to_api($existing)]);
        }

        $ins = db()->prepare('INSERT INTO devices(device_id, owner_user_id, imei, model, last_seen, last_online_at, is_online) VALUES(?,?,?,?,NOW(),NOW(),1)');
        $ins->execute([$deviceId, $u['id'], $imei, $model !== '' ? $model : null]);
        $d = find_device($deviceId);
        json_response(['ok' => true, 'assigned' => true, 'device' => device_to_api($d)]);
    }

    if ($method === 'GET' && ($m = route_match('/api/devices/:deviceId', $uri))) {
        $u = auth_user();
        $d = find_device($m['deviceId']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['error' => 'forbidden'], 403);
        json_response(['ok' => true, 'device' => device_to_api($d)]);
    }

    if ($method === 'GET' && $uri === '/api/admin/overview') {
        require_admin();
        $summary = [
            'users' => (int)db()->query('SELECT COUNT(*) c FROM users')->fetch()['c'],
            'devices' => (int)db()->query('SELECT COUNT(*) c FROM devices')->fetch()['c'],
            'onlineDevices' => (int)db()->query('SELECT COUNT(*) c FROM devices WHERE is_online = 1 AND last_seen >= (NOW() - INTERVAL 5 MINUTE)')->fetch()['c'],
            'activeSubscriptions' => (int)db()->query('SELECT COUNT(*) c FROM devices WHERE subscription_status = "active" AND subscription_until >= NOW()')->fetch()['c'],
            'monthlyRevenueMzn' => (float)db()->query('SELECT COALESCE(SUM(amount),0) total FROM payments WHERE status = "completed" AND currency="MZN" AND created_at >= (NOW() - INTERVAL 30 DAY)')->fetch()['total'],
        ];
        $expiring = db()->query('SELECT device_id, model, subscription_until FROM devices WHERE subscription_status = "active" AND subscription_until BETWEEN NOW() AND (NOW() + INTERVAL 5 DAY) ORDER BY subscription_until ASC LIMIT 20')->fetchAll();
        json_response(['ok' => true, 'summary' => $summary, 'expiringSoon' => $expiring]);
    }

    // Telemetry
    if ($method === 'POST' && ($m = route_match('/api/telemetry/:deviceId', $uri))) {
        $deviceId = trim((string)$m['deviceId']);
        if ($deviceId === '') json_response(['ok' => false, 'error' => 'missing_device'], 400);

        $payload = $body;
        $eventPayload = $payload['payload'] ?? [];
        $deviceInfo = $eventPayload['device'] ?? [];
        $statusInfo = $eventPayload['status'] ?? [];
        $imei = trim((string)($deviceInfo['imei'] ?? ''));
        $model = trim((string)($deviceInfo['model'] ?? ''));
        $networkType = trim((string)($statusInfo['networkType'] ?? ''));
        $carrier = trim((string)($statusInfo['carrier'] ?? ''));
        $batteryLevel = isset($statusInfo['batteryLevel']) ? (int)$statusInfo['batteryLevel'] : null;
        $signalLevel = isset($statusInfo['signalLevel']) ? (int)$statusInfo['signalLevel'] : null;

        $ownerUserId = null;
        $u = auth_user(false);
        if ($u) $ownerUserId = (string)$u['id'];

        if ($imei !== '') {
            $stI = db()->prepare('SELECT * FROM devices WHERE imei = ? LIMIT 1');
            $stI->execute([$imei]);
            $byImei = $stI->fetch();
            if ($byImei) {
                $deviceId = $byImei['device_id'];
                if ($ownerUserId && (empty($byImei['owner_user_id']) || (string)$byImei['owner_user_id'] === $ownerUserId)) {
                    $ownerUserId = $ownerUserId;
                } else {
                    $ownerUserId = $byImei['owner_user_id'] ?? null;
                }
            }
        }

        $ts = date('Y-m-d H:i:s');
        $ins = db()->prepare('INSERT INTO telemetry(device_id, payload, ts) VALUES(?,?,?)');
        $ins->execute([$deviceId, json_encode($payload), $ts]);

        $up = db()->prepare('INSERT INTO devices(device_id, owner_user_id, imei, model, network_type, battery_level, carrier, signal_level, is_online, last_online_at, last_seen) VALUES(?,?,?,?,?,?,?,?,1,?,?) ON DUPLICATE KEY UPDATE owner_user_id=COALESCE(owner_user_id, VALUES(owner_user_id)), imei=COALESCE(imei, VALUES(imei)), model=COALESCE(NULLIF(VALUES(model),""),model), network_type=COALESCE(NULLIF(VALUES(network_type),""),network_type), battery_level=COALESCE(VALUES(battery_level),battery_level), carrier=COALESCE(NULLIF(VALUES(carrier),""),carrier), signal_level=COALESCE(VALUES(signal_level),signal_level), is_online=1, last_online_at=VALUES(last_online_at), last_seen=VALUES(last_seen)');
        $up->execute([$deviceId, $ownerUserId, $imei !== '' ? $imei : null, $model !== '' ? $model : null, $networkType !== '' ? $networkType : null, $batteryLevel, $carrier !== '' ? $carrier : null, $signalLevel, $ts, $ts]);

        json_response(['ok' => true, 'id' => (string) db()->lastInsertId(), 'deviceId' => $deviceId]);
    }

    if ($method === 'GET' && ($m = route_match('/api/telemetry/:deviceId/history', $uri))) {
        $u = auth_user();
        $d = find_device($m['deviceId']);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['error' => 'forbidden'], 403);
        require_device_subscription($u, $d);

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
        require_device_subscription($u, $d);

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
        $deviceId = trim((string)($body['deviceId'] ?? ''));
        if ($deviceId === '') json_response(['ok' => false, 'error' => 'missing_device'], 400);
        $d = find_device($deviceId);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['error' => 'forbidden'], 403);

        $amount = $body['amount'] ?? 800;
        $currency = (string)($body['currency'] ?? 'MZN');
        $methodPay = (string)($body['method'] ?? 'manual');
        $ins = db()->prepare('INSERT INTO payments(user_id, device_id, amount, currency, method, note, media_file_id, status) VALUES(?,?,?,?,?,?,?,"pending")');
        $ins->execute([$u['id'], $deviceId, $amount, $currency, $methodPay, $body['note'] ?? null, $body['mediaFileId'] ?? null]);
        json_response(['ok' => true, 'id' => (string) db()->lastInsertId()]);
    }

    if ($method === 'POST' && $uri === '/api/payments/mpesa/checkout') {
        $u = auth_user();
        $deviceId = trim((string)($body['deviceId'] ?? ''));
        $msisdn = trim((string)($body['msisdn'] ?? ''));
        if ($deviceId === '' || $msisdn === '') json_response(['ok' => false, 'error' => 'missing_fields'], 400);
        $d = find_device($deviceId);
        if (!$d) json_response(['ok' => false, 'error' => 'not_found'], 404);
        if (!can_access_device($u, $d)) json_response(['error' => 'forbidden'], 403);

        db()->beginTransaction();
        try {
            $providerTxId = 'MPESA-' . strtoupper(bin2hex(random_bytes(6)));
            $ins = db()->prepare('INSERT INTO payments(user_id, device_id, amount, currency, method, note, status, provider_tx_id, msisdn, processed_at, processed_by) VALUES(?,?,?,?,?,?,?,?,?,NOW(),?)');
            $ins->execute([$u['id'], $deviceId, 800, 'MZN', 'mpesa_auto', 'Cobrança automática M-Pesa', 'completed', $providerTxId, $msisdn, $u['id']]);
            grant_device_subscription($deviceId, 30);
            $upU = db()->prepare('UPDATE users SET active=1 WHERE id=?');
            $upU->execute([$u['id']]);
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }

        $d2 = find_device($deviceId);
        json_response(['ok' => true, 'status' => 'completed', 'priceMzn' => 800, 'device' => device_to_api($d2)]);
    }

    if ($method === 'GET' && $uri === '/api/payments') {
        require_admin();
        $rows = db()->query('SELECT p.*, u.email, u.name FROM payments p JOIN users u ON u.id = p.user_id ORDER BY p.created_at DESC')->fetchAll();
        json_response(['ok' => true, 'payments' => $rows]);
    }

    if ($method === 'GET' && $uri === '/api/payments/mine') {
        $u = auth_user();
        $st = db()->prepare('SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC');
        $st->execute([$u['id']]);
        json_response(['ok' => true, 'payments' => $st->fetchAll()]);
    }

    if ($method === 'POST' && ($m = route_match('/api/payments/:id/process', $uri))) {
        $u = require_admin();
        $action = (string)($body['action'] ?? '');
        if (!in_array($action, ['approve', 'reject'], true)) json_response(['ok' => false, 'error' => 'invalid_action'], 400);

        $status = $action === 'approve' ? 'completed' : 'rejected';

        db()->beginTransaction();
        try {
            $sel = db()->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
            $sel->execute([$m['id']]);
            $payment = $sel->fetch();
            if (!$payment) {
                db()->rollBack();
                json_response(['ok' => false, 'error' => 'not_found'], 404);
            }

            $up = db()->prepare('UPDATE payments SET status=?, processed_at=?, processed_by=? WHERE id=?');
            $up->execute([$status, date('Y-m-d H:i:s'), $u['id'], $m['id']]);

            if ($action === 'approve') {
                $q = db()->prepare('UPDATE users SET active=1 WHERE id=?');
                $q->execute([$payment['user_id']]);
                if (!empty($payment['device_id'])) {
                    grant_device_subscription((string)$payment['device_id'], 30);
                }
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
        require_device_subscription($u, $d);

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
        if (!can_access_device($u, $d)) json_response(['error' => 'forbidden'], 403);
        require_device_subscription($u, $d);

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
