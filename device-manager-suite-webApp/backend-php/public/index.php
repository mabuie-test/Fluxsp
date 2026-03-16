<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Secret');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$cfg = [
  'db_host' => getenv('MYSQL_HOST') ?: '127.0.0.1',
  'db_port' => getenv('MYSQL_PORT') ?: '3306',
  'db_name' => getenv('MYSQL_DATABASE') ?: 'devicemgr',
  'db_user' => getenv('MYSQL_USER') ?: 'root',
  'db_pass' => getenv('MYSQL_PASSWORD') ?: '',
  'jwt_secret' => getenv('JWT_SECRET') ?: 'change_this_secret',
  'admin_registration_secret' => getenv('ADMIN_REGISTRATION_SECRET') ?: '',
  'media_dir' => getenv('MEDIA_DIR') ?: __DIR__ . '/../media',
];

if (!is_dir($cfg['media_dir'])) { @mkdir($cfg['media_dir'], 0777, true); }

$pdo = new PDO(
  sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $cfg['db_host'], $cfg['db_port'], $cfg['db_name']),
  $cfg['db_user'],
  $cfg['db_pass'],
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

function b64url_encode(string $d): string { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function b64url_decode(string $d): string { return base64_decode(strtr($d, '-_', '+/') . str_repeat('=', (4 - strlen($d) % 4) % 4)); }
function jwt_sign(array $payload, string $secret): string {
  $header = ['alg' => 'HS256', 'typ' => 'JWT'];
  $h = b64url_encode(json_encode($header));
  $p = b64url_encode(json_encode($payload));
  $sig = b64url_encode(hash_hmac('sha256', "$h.$p", $secret, true));
  return "$h.$p.$sig";
}
function jwt_verify(string $token, string $secret): ?array {
  $parts = explode('.', $token);
  if (count($parts) !== 3) return null;
  [$h, $p, $s] = $parts;
  $check = b64url_encode(hash_hmac('sha256', "$h.$p", $secret, true));
  if (!hash_equals($check, $s)) return null;
  $payload = json_decode(b64url_decode($p), true);
  if (!is_array($payload)) return null;
  if (isset($payload['exp']) && time() >= (int)$payload['exp']) return null;
  return $payload;
}
function json_body(): array {
  $raw = file_get_contents('php://input');
  $d = json_decode($raw ?: '{}', true);
  return is_array($d) ? $d : [];
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

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
if (!str_starts_with($path, '/api/')) send_json(['error' => 'not_found'], 404);
$route = substr($path, 4);
$method = $_SERVER['REQUEST_METHOD'];

try {
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
    $payload = ['id' => (string)$u['id'], 'role' => $u['role'], 'email' => $u['email'], 'exp' => time() + 60*60*24*30];
    send_json(['token' => jwt_sign($payload, $cfg['jwt_secret']), 'userId' => (string)$u['id'], 'role' => $u['role'], 'active' => (bool)$u['active']]);
  }

  if ($route === '/auth/me' && $method === 'GET') {
    $p = auth_payload($cfg['jwt_secret']);
    if (!$p || empty($p['id'])) send_json(['ok'=>false,'error'=>'no_auth'], 401);
    $q = $pdo->prepare('SELECT id,email,name,role,active,created_at FROM users WHERE id=?'); $q->execute([$p['id']]); $u = $q->fetch();
    if (!$u) send_json(['ok'=>false,'error'=>'not_found'], 404);
    send_json(['ok'=>true,'user'=>$u]);
  }

  if ($route === '/auth/register-admin' && $method === 'POST') {
    $b = json_body();
    $allowed = false;
    $provided = $_SERVER['HTTP_X_ADMIN_SECRET'] ?? ($b['adminSecret'] ?? '');
    if ($cfg['admin_registration_secret'] && hash_equals($cfg['admin_registration_secret'], (string)$provided)) $allowed = true;
    if (!$allowed) {
      $p = auth_payload($cfg['jwt_secret']);
      if ($p && ($p['role'] ?? '') === 'admin') $allowed = true;
    }
    if (!$allowed) send_json(['ok'=>false,'error'=>'forbidden'], 403);
    if (empty($b['email']) || empty($b['password'])) send_json(['ok'=>false,'error'=>'missing_fields'], 400);
    $q = $pdo->prepare('SELECT id FROM users WHERE email=?'); $q->execute([$b['email']]);
    if ($q->fetch()) send_json(['ok'=>false,'error'=>'exists'], 400);
    $ins = $pdo->prepare('INSERT INTO users(email,password_hash,name,role,active,created_at) VALUES(?,?,?,?,?,NOW())');
    $ins->execute([$b['email'], password_hash($b['password'], PASSWORD_BCRYPT), $b['name'] ?? '', 'admin', 1]);
    send_json(['ok'=>true,'userId'=>(string)$pdo->lastInsertId(),'email'=>$b['email']]);
  }

  if ($route === '/devices/public' && $method === 'GET' || $route === '/devices' && $method === 'GET' || $route === '/devices/' && $method==='GET') {
    $rows = $pdo->query('SELECT * FROM devices ORDER BY last_seen DESC')->fetchAll();
    send_json(['ok'=>true,'devices'=>$rows]);
  }

  if ($route === '/devices/my' && $method === 'GET') {
    $p = auth_payload($cfg['jwt_secret']); if (!$p) send_json(['ok'=>false,'error'=>'not_authenticated'], 401);
    $q = $pdo->prepare('SELECT * FROM devices WHERE owner_user_id=? ORDER BY last_seen DESC'); $q->execute([$p['id']]);
    send_json(['ok'=>true,'devices'=>$q->fetchAll()]);
  }

  if (preg_match('#^/devices/([^/]+)/claim$#', $route, $m) && $method === 'POST') {
    $p = auth_payload($cfg['jwt_secret']); if (!$p) send_json(['ok'=>false,'error'=>'not_authenticated'], 401);
    $deviceId = urldecode($m[1]);
    $q = $pdo->prepare('SELECT * FROM devices WHERE device_id=?'); $q->execute([$deviceId]); $d=$q->fetch();
    if (!$d) send_json(['ok'=>false,'error'=>'not_found'], 404);
    if (!empty($d['owner_user_id']) && (string)$d['owner_user_id'] !== (string)$p['id']) send_json(['ok'=>false,'error'=>'already_claimed'], 403);
    $u = $pdo->prepare('UPDATE devices SET owner_user_id=? WHERE id=?'); $u->execute([$p['id'],$d['id']]);
    send_json(['ok'=>true,'deviceId'=>$deviceId,'owner'=>(string)$p['id']]);
  }

  if (preg_match('#^/devices/([^/]+)$#', $route, $m) && $method === 'GET') {
    $p = auth_payload($cfg['jwt_secret']); if (!$p) send_json(['error'=>'no_token'], 401);
    $deviceId = urldecode($m[1]);
    $q = $pdo->prepare('SELECT * FROM devices WHERE device_id=?'); $q->execute([$deviceId]); $d=$q->fetch();
    if (!$d) send_json(['ok'=>false,'error'=>'not_found'], 404);
    send_json(['ok'=>true,'device'=>$d]);
  }

  if (preg_match('#^/telemetry/([^/]+)$#', $route, $m) && $method === 'POST') {
    $deviceId = urldecode($m[1]); $b = json_body();
    $ins = $pdo->prepare('INSERT INTO telemetries(device_id,payload_json,ts) VALUES(?,?,NOW())');
    $ins->execute([$deviceId, json_encode($b)]);
    $up = $pdo->prepare('INSERT INTO devices(device_id,last_seen,created_at) VALUES(?,NOW(),NOW()) ON DUPLICATE KEY UPDATE last_seen=NOW()');
    $up->execute([$deviceId]);
    send_json(['ok'=>true,'id'=>(string)$pdo->lastInsertId()]);
  }

  if (preg_match('#^/telemetry/([^/]+)/history$#', $route, $m) && $method === 'GET') {
    $p = auth_payload($cfg['jwt_secret']); if (!$p) send_json(['error'=>'no_token'],401);
    $deviceId = urldecode($m[1]);
    $q = $pdo->prepare('SELECT id,device_id,payload_json,ts FROM telemetries WHERE device_id=? ORDER BY ts DESC LIMIT 100');
    $q->execute([$deviceId]); $rows=$q->fetchAll();
    $items = array_map(fn($r)=>['id'=>(string)$r['id'],'deviceId'=>$r['device_id'],'payload'=>json_decode($r['payload_json'],true),'ts'=>$r['ts']],$rows);
    send_json(['ok'=>true,'total'=>count($items),'items'=>$items]);
  }

  if (preg_match('#^/telemetry/([^/]+)/items$#', $route, $m) && $method === 'GET') {
    $p = auth_payload($cfg['jwt_secret']); if (!$p) send_json(['error'=>'no_token'],401);
    $deviceId = urldecode($m[1]); $type = $_GET['type'] ?? null;
    $q = $pdo->prepare('SELECT id,device_id,payload_json,ts FROM telemetries WHERE device_id=? ORDER BY ts DESC LIMIT 500');
    $q->execute([$deviceId]); $rows=$q->fetchAll();
    $items=[];
    foreach($rows as $r){ $payload=json_decode($r['payload_json'], true); if($type && (($payload['type'] ?? null)!==$type)) continue; $items[]=['id'=>(string)$r['id'],'deviceId'=>$r['device_id'],'payload'=>$payload,'ts'=>$r['ts']]; }
    send_json(['ok'=>true,'total'=>count($items),'items'=>$items]);
  }

  if ($route === '/payments' && $method === 'POST' || $route==='/payments/'&&$method==='POST') {
    $p = auth_payload($cfg['jwt_secret']); if (!$p) send_json(['ok'=>false,'error'=>'not_authenticated'],401);
    $b = json_body();
    $ins = $pdo->prepare('INSERT INTO payments(user_id,amount,currency,status,method,note,media_file_id,created_at) VALUES(?,?,?,?,?,?,?,NOW())');
    $ins->execute([$p['id'], $b['amount'] ?? null, $b['currency'] ?? 'USD', 'pending', $b['method'] ?? null, $b['note'] ?? null, $b['mediaFileId'] ?? null]);
    send_json(['ok'=>true,'id'=>(string)$pdo->lastInsertId()]);
  }

  if ($route === '/payments/mine' && $method === 'GET') {
    $p = auth_payload($cfg['jwt_secret']); if (!$p) send_json(['ok'=>false,'error'=>'not_authenticated'],401);
    $q = $pdo->prepare('SELECT * FROM payments WHERE user_id=? ORDER BY created_at DESC'); $q->execute([$p['id']]);
    send_json(['ok'=>true,'payments'=>$q->fetchAll()]);
  }

  if ($route === '/payments' && $method === 'GET' || $route === '/payments/' && $method==='GET') {
    $p = auth_payload($cfg['jwt_secret']); if (!$p || ($p['role'] ?? '') !== 'admin') send_json(['error'=>'forbidden'],403);
    $q = $pdo->query('SELECT p.*,u.email,u.name FROM payments p LEFT JOIN users u ON p.user_id=u.id ORDER BY p.created_at DESC');
    send_json(['ok'=>true,'payments'=>$q->fetchAll()]);
  }

  if (preg_match('#^/payments/(\d+)/process$#', $route, $m) && $method === 'POST') {
    $p = auth_payload($cfg['jwt_secret']); if (!$p || ($p['role'] ?? '') !== 'admin') send_json(['error'=>'forbidden'],403);
    $id=(int)$m[1]; $b=json_body();
    $q = $pdo->prepare('SELECT * FROM payments WHERE id=?'); $q->execute([$id]); $pay=$q->fetch();
    if(!$pay) send_json(['ok'=>false,'error'=>'not_found'],404);
    $status = ($b['action'] ?? '') === 'approve' ? 'completed' : 'rejected';
    $u = $pdo->prepare('UPDATE payments SET status=?, processed_at=NOW(), processed_by=? WHERE id=?'); $u->execute([$status, $p['id'], $id]);
    if($status==='completed'){ $a = $pdo->prepare('UPDATE users SET active=1 WHERE id=?'); $a->execute([$pay['user_id']]); }
    send_json(['ok'=>true]);
  }

  if (preg_match('#^/media/list/([^/]+)$#', $route, $m) && $method === 'GET') {
    $p=auth_payload($cfg['jwt_secret']); if(!$p) send_json(['error'=>'no_token'],401);
    $deviceId=urldecode($m[1]); $q=$pdo->prepare('SELECT * FROM media_files WHERE device_id=? ORDER BY upload_date DESC'); $q->execute([$deviceId]);
    $files = array_map(fn($r)=>['fileId'=>(string)$r['id'],'filename'=>$r['filename'],'contentType'=>$r['content_type'],'uploadDate'=>$r['upload_date'],'metadata'=>['originalname'=>$r['originalname'],'deviceId'=>$r['device_id'],'checksum'=>$r['checksum']]], $q->fetchAll());
    send_json(['ok'=>true,'files'=>$files]);
  }

  if (preg_match('#^/media/([^/]+)/upload$#', $route, $m) && $method === 'POST') {
    $p=auth_payload($cfg['jwt_secret']); if(!$p) send_json(['error'=>'no_token'],401);
    if (empty($_FILES['media']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) send_json(['ok'=>false,'error'=>'no_file'],400);
    $deviceId=urldecode($m[1]); $tmp=$_FILES['media']['tmp_name'];
    $checksum = hash_file('sha256', $tmp);
    $chk = $pdo->prepare('SELECT id FROM media_files WHERE checksum=? LIMIT 1'); $chk->execute([$checksum]); $ex = $chk->fetch();
    if($ex) send_json(['ok'=>true,'exists'=>true,'fileId'=>(string)$ex['id']]);
    $ext = pathinfo($_FILES['media']['name'], PATHINFO_EXTENSION);
    $stored = bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');
    $dest = rtrim($cfg['media_dir'], '/').'/'.$stored;
    move_uploaded_file($tmp, $dest);
    $ins = $pdo->prepare('INSERT INTO media_files(device_id,filename,stored_name,originalname,content_type,checksum,upload_date) VALUES(?,?,?,?,?,?,NOW())');
    $ins->execute([$deviceId, $_FILES['media']['name'], $stored, $_FILES['media']['name'], $_FILES['media']['type'] ?: 'application/octet-stream', $checksum]);
    send_json(['ok'=>true,'fileId'=>(string)$pdo->lastInsertId(),'checksum'=>$checksum]);
  }

  if ($route === '/media/checksum' && $method === 'POST') {
    $p=auth_payload($cfg['jwt_secret']); if(!$p) send_json(['error'=>'no_token'],401);
    $b=json_body(); if(empty($b['checksum'])) send_json(['ok'=>false,'error'=>'missing_checksum'],400);
    $q=$pdo->prepare('SELECT id FROM media_files WHERE checksum=? LIMIT 1'); $q->execute([$b['checksum']]); $ex=$q->fetch();
    send_json(['ok'=>true,'exists'=>(bool)$ex,'fileId'=>$ex ? (string)$ex['id'] : null]);
  }

  if (preg_match('#^/media/download/(\d+)$#', $route, $m) && $method === 'GET') {
    $p=auth_payload($cfg['jwt_secret']); if(!$p) send_json(['error'=>'no_token'],401);
    $q=$pdo->prepare('SELECT * FROM media_files WHERE id=?'); $q->execute([(int)$m[1]]); $f=$q->fetch();
    if(!$f) send_json(['ok'=>false,'error'=>'not_found'],404);
    $full = rtrim($cfg['media_dir'], '/').'/'.$f['stored_name'];
    if(!is_file($full)) send_json(['ok'=>false,'error'=>'not_found'],404);
    header('Content-Type: '.$f['content_type']);
    header('Content-Disposition: attachment; filename="'.$f['filename'].'"');
    readfile($full); exit;
  }

  send_json(['error'=>'not_found'], 404);
} catch (Throwable $e) {
  error_log('api error: '.$e->getMessage());
  send_json(['ok'=>false,'error'=>'server_error'],500);
}
