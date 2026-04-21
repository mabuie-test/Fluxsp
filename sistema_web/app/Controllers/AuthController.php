<?php

declare(strict_types=1);

namespace App\Controllers;

final class AuthController extends BaseController
{
    public function loginForm(): void { $this->view('auth/login'); }
    public function registerForm(): void { $this->view('auth/register'); }

    public function login(): void
    {
        $this->json(['message' => 'Login processado (placeholder).']);
    }

    public function register(): void
    {
        $this->json(['message' => 'Registo processado (placeholder).'], 201);
    }

    public function logout(): void
    {
        session_destroy();
        header('Location: /');
    }
}
