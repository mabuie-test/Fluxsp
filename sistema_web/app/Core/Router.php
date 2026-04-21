<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $path, callable|array $handler): void
    {
        $this->routes[strtoupper($method)][$path] = $handler;
    }

    public function dispatch(string $method, string $uri): mixed
    {
        $method = strtoupper($method);
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        if (isset($this->routes[$method][$path])) {
            return $this->invoke($this->routes[$method][$path]);
        }

        foreach ($this->routes[$method] ?? [] as $route => $handler) {
            $pattern = '#^' . preg_replace('/\{[^}]+\}/', '([^/]+)', $route) . '$#';
            if (preg_match($pattern, $path, $matches)) {
                array_shift($matches);
                return $this->invoke($handler, $matches);
            }
        }

        http_response_code(404);
        return 'Not Found';
    }

    private function invoke(callable|array $handler, array $params = []): mixed
    {
        if (is_array($handler) && is_string($handler[0])) {
            $handler[0] = new $handler[0]();
        }

        return call_user_func_array($handler, $params);
    }
}
