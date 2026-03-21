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
    'debito' => [
        'base_url' => rtrim(getenv('DEBITO_BASE_URL') ?: 'https://my.debito.co.mz', '/'),
        'api_token' => getenv('DEBITO_API_TOKEN') ?: '',
        'wallet_id' => getenv('DEBITO_WALLET_ID') ?: '',
        'callback_url' => getenv('DEBITO_CALLBACK_URL') ?: '',
    ],
    'realtime' => [
        'enabled' => getenv('REALTIME_ENABLED') !== '0',
        'stream_ttl' => (int)(getenv('REALTIME_STREAM_TTL') ?: 45),
        'stream_max_duration' => (int)(getenv('REALTIME_STREAM_MAX_DURATION') ?: 20),
    ],
];
