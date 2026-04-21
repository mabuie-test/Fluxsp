<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Africa/Maputo');

session_name($_ENV['SESSION_NAME'] ?? 'mozacad_session');
session_set_cookie_params([
    'httponly' => filter_var($_ENV['SESSION_HTTP_ONLY'] ?? true, FILTER_VALIDATE_BOOL),
    'secure' => filter_var($_ENV['SESSION_SECURE'] ?? false, FILTER_VALIDATE_BOOL),
    'samesite' => $_ENV['SESSION_SAME_SITE'] ?? 'Lax',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
