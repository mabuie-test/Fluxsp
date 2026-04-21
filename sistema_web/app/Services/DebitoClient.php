<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;

final class DebitoClient
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => $_ENV['DEBITO_BASE_URL'],
            'timeout' => (int) ($_ENV['DEBITO_TIMEOUT'] ?? 30),
        ]);
    }

    public function post(string $uri, array $headers, array $payload): array
    {
        $response = $this->http->post($uri, ['headers' => $headers, 'json' => $payload]);
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    public function get(string $uri, array $headers): array
    {
        $response = $this->http->get($uri, ['headers' => $headers]);
        return json_decode((string) $response->getBody(), true) ?? [];
    }
}
