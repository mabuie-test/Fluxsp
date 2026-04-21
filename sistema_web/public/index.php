<?php

declare(strict_types=1);

use App\Core\Router;

require_once __DIR__ . '/../app/bootstrap.php';

$router = new Router();
$routes = array_merge(require __DIR__ . '/../routes/web.php', require __DIR__ . '/../routes/api.php');

foreach ($routes as [$method, $path, $handler]) {
    $router->add($method, $path, $handler);
}

echo $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
