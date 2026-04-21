<?php

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\HomeController;
use App\Controllers\OrderController;

return [
    ['GET', '/', [HomeController::class, 'index']],
    ['GET', '/about', [HomeController::class, 'about']],
    ['GET', '/how-it-works', [HomeController::class, 'about']],
    ['GET', '/institutions', [HomeController::class, 'about']],
    ['GET', '/pricing', [HomeController::class, 'pricing']],
    ['GET', '/faq', [HomeController::class, 'faq']],
    ['GET', '/contact', [HomeController::class, 'about']],

    ['GET', '/login', [AuthController::class, 'loginForm']],
    ['POST', '/login', [AuthController::class, 'login']],
    ['GET', '/register', [AuthController::class, 'registerForm']],
    ['POST', '/register', [AuthController::class, 'register']],
    ['POST', '/logout', [AuthController::class, 'logout']],

    ['GET', '/dashboard', [DashboardController::class, 'index']],
    ['GET', '/orders/create', [OrderController::class, 'create']],
    ['POST', '/orders', [OrderController::class, 'store']],

    ['GET', '/admin', [AdminController::class, 'index']],
];
