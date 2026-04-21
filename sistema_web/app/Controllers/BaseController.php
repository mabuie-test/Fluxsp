<?php

declare(strict_types=1);

namespace App\Controllers;

abstract class BaseController
{
    protected function view(string $view, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        require __DIR__ . '/../../public/views/' . $view . '.php';
    }

    protected function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
