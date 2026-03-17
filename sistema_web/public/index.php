<?php
require __DIR__ . '/../app/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Secret');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    exit;
}
header('Access-Control-Allow-Origin: *');

function route_match(string $pattern, string $uri): ?array {
    $regex = '#^' . preg_replace('#:([a-zA-Z_]+)#', '(?P<$1>[^/]+)', $pattern) . '$#';
    if (preg_match($regex, $uri, $m)) return array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
    return null;
}

if (!str_starts_with($uri, '/api')) {
    $target = $uri === '/' ? '/index.html' : $uri;
    $file = realpath(__DIR__ . $target);
    $base = realpath(__DIR__);
    if ($file && str_starts_with($file, $base) && is_file($file)) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $types = ['html'=>'text/html','css'=>'text/css','js'=>'application/javascript','json'=>'application/json','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','svg'=>'image/svg+xml'];
        if (isset($types[$ext])) header('Content-Type: '.$types[$ext]);
        readfile($file);
        exit;
    }
    json_response(['error'=>'not_found'],404);
}

ensure_schema();
$body = get_json_body();

if ($method === 'POST' && $uri === '/api/auth/register') {
    $email = $body['email'] ?? null;
    $password = $body['password'] ?? null;
    if (!$email || !$password) json_response(['error'=>'missing_fields'],400);
    $st = db()->prepare('SELECT id FROM users WHERE email=?');
    $st->execute([$email]);
    if ($st->fetch()) json_response(['error'=>'exists'],400);
    $ins = db()->prepare('INSERT INTO users(email,password_hash,name) VALUES(?,?,?)');
    $ins->execute([$email, password_hash($password, PASSWORD_BCRYPT), $body['name'] ?? null]);
    json_response(['ok'=>true]);
}

if ($method === 'POST' && $uri === '/api/auth/login') {
    $email = $body['email'] ?? null;
    $password = $body['password'] ?? null;
    if (!$email || !$password) json_response(['error'=>'missing_fields'],400);
    $st = db()->prepare('SELECT * FROM users WHERE email=?'); $st->execute([$email]); $u = $st->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) json_response(['error'=>'invalid_credentials'],401);
    $payload = ['id'=>(string)$u['id'],'role'=>$u['role'],'email'=>$u['email'],'exp'=>time()+60*60*24*30];
    json_response(['token'=>jwt_sign($payload),'userId'=>(string)$u['id'],'role'=>$u['role'],'active'=>(bool)$u['active']]);
}

if ($method === 'GET' && $uri === '/api/auth/me') {
    $user = auth_user();
    $st = db()->prepare('SELECT id,email,name,role,active,created_at FROM users WHERE id=?'); $st->execute([$user['id']]);
    $u = $st->fetch();
    if (!$u) json_response(['ok'=>false,'error'=>'not_found'],404);
    $u['active'] = (bool)$u['active'];
    json_response(['ok'=>true,'user'=>$u]);
}

if ($method === 'POST' && $uri === '/api/auth/register-admin') {
    global $config;
    $allowed = false;
    $secret = $_SERVER['HTTP_X_ADMIN_SECRET'] ?? ($body['adminSecret'] ?? '');
    if (!empty($config['admin_registration_secret']) && hash_equals($config['admin_registration_secret'], (string)$secret)) $allowed = true;
    if (!$allowed) { $u = auth_user(false); if ($u && is_admin($u)) $allowed = true; }
    if (!$allowed) json_response(['ok'=>false,'error'=>'forbidden'],403);
    $email = $body['email'] ?? null; $password = $body['password'] ?? null;
    if (!$email || !$password) json_response(['ok'=>false,'error'=>'missing_fields'],400);
    $st = db()->prepare('SELECT id FROM users WHERE email=?'); $st->execute([$email]); if ($st->fetch()) json_response(['ok'=>false,'error'=>'exists'],400);
    $ins = db()->prepare('INSERT INTO users(email,password_hash,name,role,active) VALUES(?,?,?,?,1)');
    $ins->execute([$email,password_hash($password,PASSWORD_BCRYPT),$body['name'] ?? '', 'admin']);
    json_response(['ok'=>true,'userId'=>(string)db()->lastInsertId(),'email'=>$email]);
}

if ($method === 'GET' && $uri === '/api/devices') {
    $rows = db()->query('SELECT * FROM devices ORDER BY last_seen DESC')->fetchAll();
    json_response(['ok'=>true,'devices'=>$rows]);
}
if ($method === 'GET' && $uri === '/api/devices/public') {
    $rows = db()->query('SELECT * FROM devices ORDER BY last_seen DESC')->fetchAll();
    json_response(['ok'=>true,'devices'=>$rows]);
}
if ($method === 'GET' && $uri === '/api/devices/my') {
    $u = auth_user();
    $st = db()->prepare('SELECT * FROM devices WHERE owner_user_id=? ORDER BY last_seen DESC'); $st->execute([$u['id']]);
    json_response(['ok'=>true,'devices'=>$st->fetchAll()]);
}
if ($method === 'GET' && ($m = route_match('/api/devices/:deviceId', $uri))) {
    $st = db()->prepare('SELECT * FROM devices WHERE device_id=?'); $st->execute([$m['deviceId']]); $d = $st->fetch();
    if (!$d) json_response(['ok'=>false,'error'=>'not_found'],404);
    json_response(['ok'=>true,'device'=>$d]);
}
if ($method === 'POST' && ($m = route_match('/api/devices/:deviceId/claim', $uri))) {
    $u = auth_user();
    $st = db()->prepare('SELECT * FROM devices WHERE device_id=?'); $st->execute([$m['deviceId']]); $d = $st->fetch();
    if (!$d) json_response(['ok'=>false,'error'=>'not_found'],404);
    if (!empty($d['owner_user_id']) && (string)$d['owner_user_id'] !== (string)$u['id']) json_response(['ok'=>false,'error'=>'already_claimed'],403);
    $up = db()->prepare('UPDATE devices SET owner_user_id=? WHERE device_id=?'); $up->execute([$u['id'],$m['deviceId']]);
    json_response(['ok'=>true,'deviceId'=>$m['deviceId'],'owner'=>(string)$u['id']]);
}

if ($method === 'POST' && ($m = route_match('/api/telemetry/:deviceId', $uri))) {
    $payload = $body;
    $ts = date('Y-m-d H:i:s');
    $ins = db()->prepare('INSERT INTO telemetry(device_id,payload,ts) VALUES(?,?,?)');
    $ins->execute([$m['deviceId'], json_encode($payload), $ts]);
    $up = db()->prepare('INSERT INTO devices(device_id,last_seen) VALUES(?,?) ON DUPLICATE KEY UPDATE last_seen=VALUES(last_seen)');
    $up->execute([$m['deviceId'],$ts]);
    json_response(['ok'=>true,'id'=>(string)db()->lastInsertId()]);
}
if ($method === 'GET' && ($m = route_match('/api/telemetry/:deviceId/history', $uri))) {
    auth_user();
    $st = db()->prepare('SELECT * FROM telemetry WHERE device_id=? ORDER BY ts DESC LIMIT 100'); $st->execute([$m['deviceId']]); $it=$st->fetchAll();
    foreach ($it as &$r) $r['payload'] = json_decode($r['payload'], true);
    json_response(['ok'=>true,'total'=>count($it),'items'=>$it]);
}
if ($method === 'GET' && ($m = route_match('/api/telemetry/:deviceId/items', $uri))) {
    auth_user();
    $type = $_GET['type'] ?? null;
    $st = db()->prepare('SELECT * FROM telemetry WHERE device_id=? ORDER BY ts DESC LIMIT 500'); $st->execute([$m['deviceId']]); $items=[];
    foreach($st->fetchAll() as $r){$r['payload']=json_decode($r['payload'],true); if(!$type || (($r['payload']['type']??null)===$type)) $items[]=$r;}
    json_response(['ok'=>true,'total'=>count($items),'items'=>$items]);
}

if ($method === 'POST' && $uri === '/api/payments') {
    $u = auth_user();
    $ins = db()->prepare('INSERT INTO payments(user_id,amount,method,note,media_file_id,status) VALUES(?,?,?,?,?,"pending")');
    $ins->execute([$u['id'],$body['amount'] ?? null,$body['method'] ?? null,$body['note'] ?? null,$body['mediaFileId'] ?? null]);
    json_response(['ok'=>true,'id'=>(string)db()->lastInsertId()]);
}
if ($method === 'GET' && $uri === '/api/payments') {
    $u = auth_user(); if(!is_admin($u)) json_response(['error'=>'forbidden'],403);
    $rows = db()->query('SELECT p.*, u.email, u.name FROM payments p JOIN users u ON u.id=p.user_id ORDER BY p.created_at DESC')->fetchAll();
    json_response(['ok'=>true,'payments'=>$rows]);
}
if ($method === 'GET' && $uri === '/api/payments/mine') {
    $u = auth_user(); $st = db()->prepare('SELECT * FROM payments WHERE user_id=? ORDER BY created_at DESC'); $st->execute([$u['id']]);
    json_response(['ok'=>true,'payments'=>$st->fetchAll()]);
}
if ($method === 'POST' && ($m = route_match('/api/payments/:id/process', $uri))) {
    $u = auth_user(); if(!is_admin($u)) json_response(['error'=>'forbidden'],403);
    $action = $body['action'] ?? '';
    $status = $action === 'approve' ? 'completed' : 'rejected';
    $up = db()->prepare('UPDATE payments SET status=?,processed_at=?,processed_by=? WHERE id=?');
    $up->execute([$status,date('Y-m-d H:i:s'),$u['id'],$m['id']]);
    if ($action === 'approve') {
      $q = db()->prepare('UPDATE users u JOIN payments p ON p.user_id=u.id SET u.active=1 WHERE p.id=?'); $q->execute([$m['id']]);
    }
    json_response(['ok'=>true]);
}

if ($method === 'GET' && ($m = route_match('/api/media/list/:deviceId', $uri))) {
    auth_user();
    $st = db()->prepare('SELECT file_id as fileId, filename, content_type as contentType, upload_date as uploadDate, checksum, device_id as deviceId FROM media WHERE device_id=? ORDER BY upload_date DESC');
    $st->execute([$m['deviceId']]);
    $files = array_map(fn($r) => ['fileId'=>$r['fileId'],'filename'=>$r['filename'],'contentType'=>$r['contentType'],'uploadDate'=>$r['uploadDate'],'metadata'=>['checksum'=>$r['checksum'],'deviceId'=>$r['deviceId']]], $st->fetchAll());
    json_response(['ok'=>true,'files'=>$files]);
}
if ($method === 'POST' && $uri === '/api/media/checksum') {
    auth_user();
    $checksum = $body['checksum'] ?? null;
    if (!$checksum) json_response(['ok'=>false,'error'=>'missing_checksum'],400);
    $st = db()->prepare('SELECT file_id FROM media WHERE checksum=?'); $st->execute([$checksum]); $f=$st->fetch();
    json_response(['ok'=>true,'exists'=>(bool)$f,'fileId'=>$f['file_id'] ?? null]);
}
if ($method === 'POST' && ($m = route_match('/api/media/:deviceId/upload', $uri))) {
    auth_user();
    if (!isset($_FILES['media']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) json_response(['ok'=>false,'error'=>'no_file'],400);
    $tmp = $_FILES['media']['tmp_name']; $checksum = hash_file('sha256', $tmp);
    $st = db()->prepare('SELECT file_id FROM media WHERE checksum=?'); $st->execute([$checksum]); $e = $st->fetch();
    if ($e) json_response(['ok'=>true,'exists'=>true,'fileId'=>$e['file_id']]);
    $fileId = bin2hex(random_bytes(16));
    $safe = $fileId . '_' . basename($_FILES['media']['name']);
    $dest = $config['media_dir'] . '/' . $safe;
    if (!is_dir($config['media_dir'])) mkdir($config['media_dir'], 0777, true);
    if (!move_uploaded_file($tmp, $dest)) json_response(['ok'=>false,'error'=>'upload_failed'],500);
    $ins = db()->prepare('INSERT INTO media(file_id,device_id,filename,content_type,checksum,storage_path) VALUES(?,?,?,?,?,?)');
    $ins->execute([$fileId,$m['deviceId'],$_FILES['media']['name'],$_FILES['media']['type'] ?: 'application/octet-stream',$checksum,$safe]);
    json_response(['ok'=>true,'fileId'=>$fileId,'checksum'=>$checksum]);
}
if ($method === 'GET' && ($m = route_match('/api/media/download/:fileId', $uri))) {
    auth_user();
    $st = db()->prepare('SELECT * FROM media WHERE file_id=?'); $st->execute([$m['fileId']]); $f = $st->fetch();
    if (!$f) json_response(['ok'=>false,'error'=>'not_found'],404);
    $path = $config['media_dir'] . '/' . $f['storage_path'];
    if (!is_file($path)) json_response(['ok'=>false,'error'=>'not_found'],404);
    header('Content-Type: ' . ($f['content_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . basename($f['filename']) . '"');
    readfile($path); exit;
}

json_response(['error'=>'not_found'],404);
