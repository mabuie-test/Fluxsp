<?php
return [
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'devicemgr',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'jwt_secret' => getenv('JWT_SECRET') ?: 'change-me',
    'admin_registration_secret' => getenv('ADMIN_REGISTRATION_SECRET') ?: '',
    'media_dir' => dirname(__DIR__) . '/storage/media',
];
