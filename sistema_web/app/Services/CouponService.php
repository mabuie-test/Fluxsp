<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class CouponService
{
    public function resolvePercent(?string $code): float
    {
        if (!$code) return 0.0;
        $db = Database::connection();
        $stmt = $db->prepare('SELECT discount_percent FROM coupons WHERE code = :code AND is_active = 1 AND (expires_at IS NULL OR expires_at >= NOW()) LIMIT 1');
        $stmt->execute(['code' => $code]);
        return (float) ($stmt->fetch()['discount_percent'] ?? 0.0);
    }
}
