<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

final class MpesaMsisdnValidator
{
    public function sanitize(string $msisdn): string
    {
        $normalized = preg_replace('/\D+/', '', $msisdn) ?? '';
        if (!preg_match('/^(84|85)\d{7}$/', $normalized)) {
            throw new InvalidArgumentException('Número M-Pesa inválido. Use 84xxxxxxx ou 85xxxxxxx.');
        }

        return $normalized;
    }
}
