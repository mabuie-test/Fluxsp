<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PaymentProviderInterface;

final class DebitoMpesaProvider implements PaymentProviderInterface
{
    public function __construct(
        private readonly DebitoClient $client = new DebitoClient(),
        private readonly DebitoAuthService $authService = new DebitoAuthService(),
        private readonly DebitoLoggerService $logger = new DebitoLoggerService(),
    ) {}

    public function initiate(array $payload): array
    {
        $token = $this->authService->bearerToken();
        $walletId = $_ENV['DEBITO_WALLET_ID'];

        $response = $this->client->post(
            "/api/v1/wallets/{$walletId}/c2b/mpesa",
            ['Authorization' => "Bearer {$token}"],
            $payload
        );

        $this->logger->log('initiate', ['payload' => $payload, 'response' => $response]);
        return $response;
    }

    public function checkStatus(string $externalReference): array
    {
        $token = $this->authService->bearerToken();
        $response = $this->client->get("/api/v1/transactions/{$externalReference}/status", ['Authorization' => "Bearer {$token}"]);
        $this->logger->log('status', ['reference' => $externalReference, 'response' => $response]);
        return $response;
    }
}
