<?php

declare(strict_types=1);

namespace App\Services;

final class DebitoAuthService
{
    public function __construct(private readonly DebitoClient $client = new DebitoClient()) {}

    public function bearerToken(): string
    {
        $useStatic = filter_var($_ENV['DEBITO_USE_STATIC_TOKEN'] ?? true, FILTER_VALIDATE_BOOL);
        if ($useStatic && !empty($_ENV['DEBITO_TOKEN'])) {
            return $_ENV['DEBITO_TOKEN'];
        }

        $response = $this->client->post('/api/v1/login', [], [
            'email' => $_ENV['DEBITO_EMAIL'] ?? '',
            'password' => $_ENV['DEBITO_PASSWORD'] ?? '',
        ]);

        return $response['token'] ?? '';
    }
}
