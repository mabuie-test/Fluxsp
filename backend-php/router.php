<?php
$public = __DIR__ . '/public';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = realpath($public . $uri);

if ($file && str_starts_with($file, realpath($public)) && is_file($file)) {
    return false; // serve static file
}

if (str_starts_with($uri, '/api/')) {
    require $public . '/api/index.php';
    return true;
}

// fallback for frontend routes
$index = $public . '/index.html';
if (is_file($index)) {
    header('Content-Type: text/html; charset=UTF-8');
    readfile($index);
    return true;
}

http_response_code(404);
echo 'not_found';
