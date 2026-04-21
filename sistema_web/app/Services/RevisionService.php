<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class RevisionService
{
    public function request(int $orderId, int $userId, string $comment): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('INSERT INTO revisions (order_id,user_id,status,comment,created_at,updated_at) VALUES (:o,:u,:s,:c,NOW(),NOW())');
        $stmt->execute(['o' => $orderId, 'u' => $userId, 's' => 'requested', 'c' => $comment]);
    }
}
