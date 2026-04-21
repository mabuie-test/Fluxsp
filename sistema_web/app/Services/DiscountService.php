<?php

declare(strict_types=1);

namespace App\Services;

final class DiscountService
{
    public function apply(?array $discount, float $subtotal, array $extras): array
    {
        if (!$discount) {
            return ['amount' => 0.0, 'meta' => null];
        }

        $amount = 0.0;
        if ($discount['discount_type'] === 'percent') {
            $amount = $subtotal * ((float) $discount['discount_value'] / 100);
        }
        if ($discount['discount_type'] === 'fixed') {
            $amount = min($subtotal, (float) $discount['discount_value']);
        }
        if ($discount['discount_type'] === 'extra_waiver' && !empty($discount['extra_code']) && !empty($extras[$discount['extra_code']])) {
            $amount = (float) $extras[$discount['extra_code']];
        }

        return ['amount' => round($amount, 2), 'meta' => $discount];
    }
}
