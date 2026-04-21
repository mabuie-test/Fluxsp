<?php

declare(strict_types=1);

namespace App\Services;

final class DebitoStatusMapper
{
    public function map(string $providerStatus): string
    {
        $status = strtolower($providerStatus);

        return match ($status) {
            'paid', 'success', 'completed' => 'paid',
            'processing', 'in_progress' => 'processing',
            'pending', 'created' => 'pending_confirmation',
            'cancelled' => 'cancelled',
            'expired' => 'expired',
            default => 'failed',
        };
    }
}
