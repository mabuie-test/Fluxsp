<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\UserDiscountRepository;

final class DiscountUsageLoggerService
{
    public function __construct(private readonly UserDiscountRepository $discountRepository = new UserDiscountRepository()) {}

    public function log(int $discountId, int $userId, int $orderId, float $amount, array $details = []): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('INSERT INTO discount_usage_logs (user_discount_id,user_id,order_id,amount_discounted,details_json,created_at) VALUES (:d,:u,:o,:a,:j,NOW())');
        $stmt->execute(['d' => $discountId, 'u' => $userId, 'o' => $orderId, 'a' => $amount, 'j' => json_encode($details)]);
        $this->discountRepository->incrementUsage($discountId);
    }
}
