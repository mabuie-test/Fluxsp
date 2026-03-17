<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Secret');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

$cfg = [
  'db_host' => getenv('MYSQL_HOST') ?: '127.0.0.1',
  'db_port' => getenv('MYSQL_PORT') ?: '3306',
  'db_name' => getenv('MYSQL_DATABASE') ?: 'devicemgr',
  'db_user' => getenv('MYSQL_USER') ?: 'root',
  'db_pass' => getenv('MYSQL_PASSWORD') ?: '',
  'jwt_secret' => getenv('JWT_SECRET') ?: 'change_this_secret',
  'admin_registration_secret' => getenv('ADMIN_REGISTRATION_SECRET') ?: '',
  'media_dir' => getenv('MEDIA_DIR') ?: __DIR__ . '/../media',
  'app_base_url' => getenv('APP_BASE_URL') ?: '',
  'mail_from' => getenv('MAIL_FROM') ?: 'no-reply@localhost',
  'mail_from_name' => getenv('MAIL_FROM_NAME') ?: 'DeviceMgr',
  'smtp_host' => getenv('SMTP_HOST') ?: '',
  'smtp_port' => getenv('SMTP_PORT') ?: '587',
  'smtp_username' => getenv('SMTP_USERNAME') ?: '',
  'smtp_password' => getenv('SMTP_PASSWORD') ?: '',
  'smtp_secure' => getenv('SMTP_SECURE') ?: 'tls',
];
if (!is_dir($cfg['media_dir'])) { @mkdir($cfg['media_dir'], 0777, true); }

$pdo = new PDO(
  sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $cfg['db_host'], $cfg['db_port'], $cfg['db_name']),
  $cfg['db_user'],
  $cfg['db_pass'],
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);


$pdo->exec('CREATE TABLE IF NOT EXISTS password_resets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_password_resets_user (user_id),
  UNIQUE KEY uq_password_reset_token_hash (token_hash),
  CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)');

function b64url_encode(string $d): string { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function b64url_decode(string $d): string { return base64_decode(strtr($d, '-_', '+/') . str_repeat('=', (4 - strlen($d) % 4) % 4)); }
function jwt_sign(array $payload, string $secret): string {
  $h = b64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
  $p = b64url_encode(json_encode($payload));
  $s = b64url_encode(hash_hmac('sha256', "$h.$p", $secret, true));
  return "$h.$p.$s";
}
function jwt_verify(string $token, string $secret): ?array {
  $parts = explode('.', $token);
  if (count($parts) !== 3) return null;
  [$h, $p, $s] = $parts;
  $expected = b64url_encode(hash_hmac('sha256', "$h.$p", $secret, true));
  if (!hash_equals($expected, $s)) return null;
  $payload = json_decode(b64url_decode($p), true);
  if (!is_array($payload)) return null;
  if (isset($payload['exp']) && time() >= (int)$payload['exp']) return null;
  return $payload;
}
function json_body(): array {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw ?: '{}', true);
  return is_array($data) ? $data : [];
}
function send_json(array $data, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json');
  echo json_encode($data);
  exit;
}
function auth_payload(string $secret): ?array {
  $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/Bearer\s+(.+)/i', $auth, $m)) return null;
  return jwt_verify(trim($m[1]), $secret);
}
function to_epoch_ms($v): ?int {
  if ($v === null || $v === '') return null;
  $t = strtotime((string)$v);
  return $t === false ? null : (int)$t * 1000;
}
function map_device(array $d): array {
  return [
    '_id' => (string)$d['id'],
    'id' => (string)$d['id'],
    'deviceId' => $d['device_id'],
    'owner' => $d['owner_user_id'] !== null ? (string)$d['owner_user_id'] : null,
    'name' => $d['name'],
    'consent' => [
      'accepted' => $d['consent_accepted'] !== null ? (bool)$d['consent_accepted'] : null,
      'ts' => to_epoch_ms($d['consent_ts']),
      'textVersion' => $d['consent_text_version']
    ],
    'lastSeen' => to_epoch_ms($d['last_seen']),
    'createdAt' => to_epoch_ms($d['created_at'])
  ];
}
function map_payment(array $p): array {
  $out = [
    '_id' => (string)$p['id'],
    'id' => (string)$p['id'],
    'user' => isset($p['user_id']) ? (string)$p['user_id'] : null,
    'amount' => $p['amount'] !== null ? (float)$p['amount'] : null,
    'currency' => $p['currency'],
    'status' => $p['status'],
    'method' => $p['method'],
    'note' => $p['note'],
    'mediaFileId' => $p['media_file_id'] !== null ? (string)$p['media_file_id'] : null,
    'createdAt' => to_epoch_ms($p['created_at']),
    'processedAt' => to_epoch_ms($p['processed_at']),
    'processedBy' => $p['processed_by'] !== null ? (string)$p['processed_by'] : null,
  ];
  if (isset($p['user_email']) || isset($p['user_name'])) {
    $out['user'] = [
      '_id' => isset($p['user_id']) ? (string)$p['user_id'] : null,
      'email' => $p['user_email'] ?? null,
      'name' => $p['user_name'] ?? null,
    ];
  }
  return $out;
}
function map_telemetry(array $r): array {
  return [
    '_id' => (string)$r['id'],
    'id' => (string)$r['id'],
    'deviceId' => $r['device_id'],
    'payload' => json_decode($r['payload_json'], true),
    'ts' => to_epoch_ms($r['ts'])
  ];
}
function require_auth(string $secret): array {
  $p = auth_payload($secret);
  if (!$p || empty($p['id'])) send_json(['error' => 'no_token'], 401);
  return $p;
}
function save_uploaded_media(PDO $pdo, string $mediaDir, string $deviceId): array {
  if (empty($_FILES['media']) || ($_FILES['media']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) send_json(['ok' => false, 'error' => 'no_file'], 400);
  $tmp = $_FILES['media']['tmp_name'];
  $checksum = hash_file('sha256', $tmp);
  $chk = $pdo->prepare('SELECT id FROM media_files WHERE checksum=? LIMIT 1');
  $chk->execute([$checksum]);
  $existing = $chk->fetch();
  if ($existing) return ['ok' => true, 'exists' => true, 'fileId' => (string)$existing['id']];

  $ext = pathinfo((string)$_FILES['media']['name'], PATHINFO_EXTENSION);
  $stored = bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');
  $dest = rtrim($mediaDir, '/') . '/' . $stored;
  if (!move_uploaded_file($tmp, $dest)) send_json(['ok' => false, 'error' => 'upload_failed'], 500);

  $ins = $pdo->prepare('INSERT INTO media_files(device_id,filename,stored_name,originalname,content_type,checksum,upload_date) VALUES(?,?,?,?,?,?,NOW())');
  $ins->execute([
    $deviceId,
    $_FILES['media']['name'],
    $stored,
    $_FILES['media']['name'],
    $_FILES['media']['type'] ?: 'application/octet-stream',
    $checksum
  ]);

  return ['ok' => true, 'fileId' => (string)$pdo->lastInsertId(), 'checksum' => $checksum];
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if (!str_starts_with($path, '/api/')) send_json(['error' => 'not_found'], 404);
$route = substr($path, 4);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';


$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
  require_once $autoload;
}

function send_reset_email(array $cfg, string $to, string $token): bool {
  $base = rtrim($cfg['app_base_url'] ?: (((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '/');
  $resetUrl = $base . '/reset-password.html?token=' . urlencode($token);

  $subject = 'Recuperação de senha - DeviceMgr';
  $html = '<p>Recebemos um pedido para redefinir a sua senha.</p>'
    . '<p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES) . '">Clique aqui para redefinir a senha</a></p>'
    . '<p>Se não foi você, ignore este email.</p>';

  if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    try {
      $mail = new PHPMailer\PHPMailer\PHPMailer(true);
      if (!empty($cfg['smtp_host'])) {
        $mail->isSMTP();
        $mail->Host = $cfg['smtp_host'];
        $mail->Port = (int)$cfg['smtp_port'];
        $mail->SMTPAuth = !empty($cfg['smtp_username']);
        $mail->Username = $cfg['smtp_username'];
        $mail->Password = $cfg['smtp_password'];
        if (!empty($cfg['smtp_secure'])) $mail->SMTPSecure = $cfg['smtp_secure'];
      }
      $mail->setFrom($cfg['mail_from'], $cfg['mail_from_name']);
      $mail->addAddress($to);
      $mail->isHTML(true);
      $mail->Subject = $subject;
      $mail->Body = $html;
      $mail->AltBody = 'Recuperação de senha: ' . $resetUrl;
      return $mail->send();
    } catch (Throwable $e) {
      error_log('mail error: ' . $e->getMessage());
      return false;
    }
  }

  $headers = "MIME-Version: 1.0
"
    . "Content-type:text/html;charset=UTF-8
"
    . 'From: ' . $cfg['mail_from'] . "
";
  return @mail($to, $subject, $html, $headers);
}

try {
  // auth
  if ($route === '/auth/register' && $method === 'POST') {
    $b = json_body();
    if (empty($b['email']) || empty($b['password'])) send_json(['error' => 'missing_fields'], 400);
    $q = $pdo->prepare('SELECT id FROM users WHERE email=?'); $q->execute([$b['email']]);
    if ($q->fetch()) send_json(['error' => 'exists'], 400);
    $ins = $pdo->prepare('INSERT INTO users(email,password_hash,name,role,active,created_at) VALUES(?,?,?,?,?,NOW())');
    $ins->execute([$b['email'], password_hash($b['password'], PASSWORD_BCRYPT), $b['name'] ?? '', 'user', 0]);
    send_json(['ok' => true]);
  }

  if ($route === '/auth/login' && $method === 'POST') {
    $b = json_body();
    if (empty($b['email']) || empty($b['password'])) send_json(['error' => 'missing_fields'], 400);
    $q = $pdo->prepare('SELECT * FROM users WHERE email=?'); $q->execute([$b['email']]); $u = $q->fetch();
    if (!$u || !password_verify($b['password'], $u['password_hash'])) send_json(['error' => 'invalid_credentials'], 401);
    $payload = ['id' => (string)$u['id'], 'role' => $u['role'], 'email' => $u['email'], 'exp' => time() + 60 * 60 * 24 * 30];
    send_json(['token' => jwt_sign($payload, $cfg['jwt_secret']), 'userId' => (string)$u['id'], 'role' => $u['role'], 'active' => (bool)$u['active']]);
  }


  if ($route === '/auth/forgot-password' && $method === 'POST') {
    $b = json_body();
    $email = strtolower(trim((string)($b['email'] ?? '')));
    if (!$email) send_json(['ok' => true]);

    $q = $pdo->prepare('SELECT id,email FROM users WHERE email=? LIMIT 1');
    $q->execute([$email]);
    $u = $q->fetch();
    if ($u) {
      $token = bin2hex(random_bytes(32));
      $tokenHash = hash('sha256', $token);
      $pdo->prepare('UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$u['id']]);
      $ins = $pdo->prepare('INSERT INTO password_resets(user_id,token_hash,expires_at,used_at,created_at) VALUES(?,?,DATE_ADD(NOW(), INTERVAL 1 HOUR),NULL,NOW())');
      $ins->execute([$u['id'], $tokenHash]);
      send_reset_email($cfg, $u['email'], $token);
    }
    send_json(['ok' => true]);
  }

  if ($route === '/auth/reset-password' && $method === 'POST') {
    $b = json_body();
    $token = (string)($b['token'] ?? '');
    $newPassword = (string)($b['password'] ?? '');
    if (!$token || strlen($newPassword) < 6) send_json(['ok' => false, 'error' => 'invalid_request'], 400);

    $tokenHash = hash('sha256', $token);
    $q = $pdo->prepare('SELECT * FROM password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at > NOW() LIMIT 1');
    $q->execute([$tokenHash]);
    $row = $q->fetch();
    if (!$row) send_json(['ok' => false, 'error' => 'invalid_or_expired_token'], 400);

    $u = $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');
    $u->execute([password_hash($newPassword, PASSWORD_BCRYPT), $row['user_id']]);
    $pdo->prepare('UPDATE password_resets SET used_at=NOW() WHERE id=?')->execute([$row['id']]);
    send_json(['ok' => true]);
  }

  if ($route === '/auth/me' && $method === 'GET') {
    $p = require_auth($cfg['jwt_secret']);
    $q = $pdo->prepare('SELECT id,email,name,role,active,created_at FROM users WHERE id=?');
    $q->execute([$p['id']]);
    $u = $q->fetch();
    if (!$u) send_json(['ok' => false, 'error' => 'not_found'], 404);
    send_json(['ok' => true, 'user' => $u]);
  }

  if ($route === '/auth/register-admin' && $method === 'POST') {
    $b = json_body();
    $allowed = false;
    $provided = (string)($_SERVER['HTTP_X_ADMIN_SECRET'] ?? ($b['adminSecret'] ?? ''));
    if ($cfg['admin_registration_secret'] && hash_equals($cfg['admin_registration_secret'], $provided)) $allowed = true;
    if (!$allowed) {
      $p = auth_payload($cfg['jwt_secret']);
      if ($p && ($p['role'] ?? '') === 'admin') $allowed = true;
    }
    if (!$allowed) send_json(['ok' => false, 'error' => 'forbidden'], 403);
    if (empty($b['email']) || empty($b['password'])) send_json(['ok' => false, 'error' => 'missing_fields'], 400);

    $q = $pdo->prepare('SELECT id FROM users WHERE email=?'); $q->execute([$b['email']]);
    if ($q->fetch()) send_json(['ok' => false, 'error' => 'exists'], 400);

    $ins = $pdo->prepare('INSERT INTO users(email,password_hash,name,role,active,created_at) VALUES(?,?,?,?,?,NOW())');
    $ins->execute([$b['email'], password_hash($b['password'], PASSWORD_BCRYPT), $b['name'] ?? '', 'admin', 1]);
    send_json(['ok' => true, 'userId' => (string)$pdo->lastInsertId(), 'email' => $b['email']]);
  }

  // devices
  if ((($route === '/devices/public') || ($route === '/devices') || ($route === '/devices/')) && $method === 'GET') {
    $rows = $pdo->query('SELECT * FROM devices ORDER BY last_seen DESC')->fetchAll();
    send_json(['ok' => true, 'devices' => array_map('map_device', $rows)]);
  }

  if ($route === '/devices/my' && $method === 'GET') {
    $p = require_auth($cfg['jwt_secret']);
    $q = $pdo->prepare('SELECT * FROM devices WHERE owner_user_id=? ORDER BY last_seen DESC');
    $q->execute([$p['id']]);
    send_json(['ok' => true, 'devices' => array_map('map_device', $q->fetchAll())]);
  }

  if (preg_match('#^/devices/([^/]+)/claim$#', $route, $m) && $method === 'POST') {
    $p = require_auth($cfg['jwt_secret']);
    $deviceId = urldecode($m[1]);

    $q = $pdo->prepare('SELECT * FROM devices WHERE device_id=?'); $q->execute([$deviceId]);
    $d = $q->fetch();
    if (!$d) send_json(['ok' => false, 'error' => 'not_found'], 404);
    if (!empty($d['owner_user_id']) && (string)$d['owner_user_id'] !== (string)$p['id']) send_json(['ok' => false, 'error' => 'already_claimed'], 403);

    $u = $pdo->prepare('UPDATE devices SET owner_user_id=? WHERE id=?'); $u->execute([$p['id'], $d['id']]);
    send_json(['ok' => true, 'deviceId' => $deviceId, 'owner' => (string)$p['id']]);
  }

  if (preg_match('#^/devices/([^/]+)$#', $route, $m) && $method === 'GET') {
    require_auth($cfg['jwt_secret']);
    $deviceId = urldecode($m[1]);
    $q = $pdo->prepare('SELECT * FROM devices WHERE device_id=?'); $q->execute([$deviceId]);
    $d = $q->fetch();
    if (!$d) send_json(['ok' => false, 'error' => 'not_found'], 404);
    send_json(['ok' => true, 'device' => map_device($d)]);
  }

  // telemetry
  if (preg_match('#^/telemetry/([^/]+)$#', $route, $m) && $method === 'POST') {
    $deviceId = urldecode($m[1]);
    $body = json_body();
    $ins = $pdo->prepare('INSERT INTO telemetries(device_id,payload_json,ts) VALUES(?,?,NOW())');
    $ins->execute([$deviceId, json_encode($body)]);
    $up = $pdo->prepare('INSERT INTO devices(device_id,last_seen,created_at) VALUES(?,NOW(),NOW()) ON DUPLICATE KEY UPDATE last_seen=NOW()');
    $up->execute([$deviceId]);
    send_json(['ok' => true, 'id' => (string)$pdo->lastInsertId()]);
  }

  if (preg_match('#^/telemetry/([^/]+)/history$#', $route, $m) && $method === 'GET') {
    require_auth($cfg['jwt_secret']);
    $deviceId = urldecode($m[1]);
    $q = $pdo->prepare('SELECT id,device_id,payload_json,ts FROM telemetries WHERE device_id=? ORDER BY ts DESC LIMIT 100');
    $q->execute([$deviceId]);
    $items = array_map('map_telemetry', $q->fetchAll());
    send_json(['ok' => true, 'total' => count($items), 'items' => $items]);
  }

  if (preg_match('#^/telemetry/([^/]+)/items$#', $route, $m) && $method === 'GET') {
    require_auth($cfg['jwt_secret']);
    $deviceId = urldecode($m[1]);
    $type = $_GET['type'] ?? null;

    $q = $pdo->prepare('SELECT id,device_id,payload_json,ts FROM telemetries WHERE device_id=? ORDER BY ts DESC LIMIT 500');
    $q->execute([$deviceId]);
    $rows = $q->fetchAll();

    $items = [];
    foreach ($rows as $r) {
      $payload = json_decode($r['payload_json'], true);
      if ($type && (($payload['type'] ?? null) !== $type)) continue;
      $items[] = map_telemetry($r);
    }
    send_json(['ok' => true, 'total' => count($items), 'items' => $items]);
  }

  // payments
  if (($route === '/payments' || $route === '/payments/') && $method === 'POST') {
    $p = require_auth($cfg['jwt_secret']);
    $b = json_body();
    $ins = $pdo->prepare('INSERT INTO payments(user_id,amount,currency,status,method,note,media_file_id,created_at) VALUES(?,?,?,?,?,?,?,NOW())');
    $ins->execute([$p['id'], $b['amount'] ?? null, $b['currency'] ?? 'USD', 'pending', $b['method'] ?? null, $b['note'] ?? null, $b['mediaFileId'] ?? null]);
    send_json(['ok' => true, 'id' => (string)$pdo->lastInsertId()]);
  }

  if ($route === '/payments/mine' && $method === 'GET') {
    $p = require_auth($cfg['jwt_secret']);
    $q = $pdo->prepare('SELECT * FROM payments WHERE user_id=? ORDER BY created_at DESC');
    $q->execute([$p['id']]);
    send_json(['ok' => true, 'payments' => array_map('map_payment', $q->fetchAll())]);
  }

  if (($route === '/payments' || $route === '/payments/') && $method === 'GET') {
    $p = require_auth($cfg['jwt_secret']);
    if (($p['role'] ?? '') !== 'admin') send_json(['error' => 'forbidden'], 403);
    $q = $pdo->query('SELECT p.*,u.email AS user_email,u.name AS user_name FROM payments p LEFT JOIN users u ON p.user_id=u.id ORDER BY p.created_at DESC');
    send_json(['ok' => true, 'payments' => array_map('map_payment', $q->fetchAll())]);
  }

  if (preg_match('#^/payments/(\d+)/process$#', $route, $m) && $method === 'POST') {
    $p = require_auth($cfg['jwt_secret']);
    if (($p['role'] ?? '') !== 'admin') send_json(['error' => 'forbidden'], 403);

    $id = (int)$m[1];
    $b = json_body();
    $q = $pdo->prepare('SELECT * FROM payments WHERE id=?'); $q->execute([$id]);
    $pay = $q->fetch();
    if (!$pay) send_json(['ok' => false, 'error' => 'not_found'], 404);

    $status = ($b['action'] ?? '') === 'approve' ? 'completed' : 'rejected';
    $u = $pdo->prepare('UPDATE payments SET status=?, processed_at=NOW(), processed_by=? WHERE id=?');
    $u->execute([$status, $p['id'], $id]);
    if ($status === 'completed') {
      $a = $pdo->prepare('UPDATE users SET active=1 WHERE id=?');
      $a->execute([$pay['user_id']]);
    }
    send_json(['ok' => true]);
  }

  // media
  if (preg_match('#^/media/list/([^/]+)$#', $route, $m) && $method === 'GET') {
    require_auth($cfg['jwt_secret']);
    $deviceId = urldecode($m[1]);
    $q = $pdo->prepare('SELECT * FROM media_files WHERE device_id=? ORDER BY upload_date DESC');
    $q->execute([$deviceId]);
    $rows = $q->fetchAll();

    $files = array_map(function ($r) {
      return [
        'fileId' => (string)$r['id'],
        'filename' => $r['filename'],
        'contentType' => $r['content_type'],
        'uploadDate' => to_epoch_ms($r['upload_date']),
        'metadata' => [
          'originalname' => $r['originalname'],
          'deviceId' => $r['device_id'],
          'checksum' => $r['checksum']
        ]
      ];
    }, $rows);
    send_json(['ok' => true, 'files' => $files]);
  }

  if (preg_match('#^/media/([^/]+)/upload$#', $route, $m) && $method === 'POST') {
    require_auth($cfg['jwt_secret']);
    $deviceId = urldecode($m[1]);
    send_json(save_uploaded_media($pdo, $cfg['media_dir'], $deviceId));
  }

  if (($route === '/media/payment/upload') && $method === 'POST') {
    $p = require_auth($cfg['jwt_secret']);
    $deviceId = 'payment_user_' . $p['id'];
    send_json(save_uploaded_media($pdo, $cfg['media_dir'], $deviceId));
  }

  if ($route === '/media/checksum' && $method === 'POST') {
    require_auth($cfg['jwt_secret']);
    $b = json_body();
    if (empty($b['checksum'])) send_json(['ok' => false, 'error' => 'missing_checksum'], 400);
    $q = $pdo->prepare('SELECT id FROM media_files WHERE checksum=? LIMIT 1');
    $q->execute([$b['checksum']]);
    $ex = $q->fetch();
    send_json(['ok' => true, 'exists' => (bool)$ex, 'fileId' => $ex ? (string)$ex['id'] : null]);
  }

  if (preg_match('#^/media/download/(\d+)$#', $route, $m) && $method === 'GET') {
    require_auth($cfg['jwt_secret']);
    $q = $pdo->prepare('SELECT * FROM media_files WHERE id=?');
    $q->execute([(int)$m[1]]);
    $f = $q->fetch();
    if (!$f) send_json(['ok' => false, 'error' => 'not_found'], 404);

    $full = rtrim($cfg['media_dir'], '/') . '/' . $f['stored_name'];
    if (!is_file($full)) send_json(['ok' => false, 'error' => 'not_found'], 404);
    header('Content-Type: ' . $f['content_type']);
    header('Content-Disposition: attachment; filename="' . $f['filename'] . '"');
    readfile($full);
    exit;
  }

  send_json(['error' => 'not_found'], 404);
} catch (Throwable $e) {
  error_log('api error: ' . $e->getMessage());
  send_json(['ok' => false, 'error' => 'server_error'], 500);
}
