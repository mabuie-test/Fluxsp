<?php
$public = __DIR__ . '/public';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = realpath($public . $uri);

if ($file && str_starts_with($file, realpath($public)) && is_file($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = [
        'html' => 'text/html; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'webp' => 'image/webp',
    ];
    if (isset($types[$ext])) header('Content-Type: ' . $types[$ext]);
    readfile($file);
    return true;
}

if (str_starts_with($uri, '/api/')) {
    require $public . '/api/index.php';
    return true;
}

// fallback for frontend routes
$index = $public . '/login.html';
if (is_file($index)) {
    header('Content-Type: text/html; charset=UTF-8');
    readfile($index);
    return true;
}

http_response_code(404);
echo 'not_found';
