<?php

declare(strict_types=1);

namespace App\Contracts;

interface PaymentProviderInterface
{
    public function initiate(array $payload): array;

    public function checkStatus(string $externalReference): array;
}
