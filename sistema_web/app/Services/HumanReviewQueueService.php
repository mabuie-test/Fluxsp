<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class HumanReviewQueueService
{
    public function enqueue(int $orderId, ?int $reviewerId = null): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('INSERT INTO human_review_queue (order_id,reviewer_id,status,created_at,updated_at) VALUES (:o,:r,:s,NOW(),NOW())');
        $stmt->execute(['o' => $orderId, 'r' => $reviewerId, 's' => 'pending']);
    }
}
