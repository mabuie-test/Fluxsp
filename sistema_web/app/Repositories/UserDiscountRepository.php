<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserDiscountRepository extends BaseRepository
{
    public function findActiveForUser(int $userId, int $workTypeId): array
    {
        $sql = "SELECT * FROM user_discounts
                WHERE user_id=:user_id
                  AND is_active=1
                  AND (work_type_id IS NULL OR work_type_id=:work_type_id)
                  AND (starts_at IS NULL OR starts_at <= NOW())
                  AND (ends_at IS NULL OR ends_at >= NOW())
                  AND (usage_limit IS NULL OR used_count < usage_limit)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId, 'work_type_id' => $workTypeId]);
        return $stmt->fetchAll();
    }

    public function incrementUsage(int $discountId): void
    {
        $stmt = $this->db->prepare('UPDATE user_discounts SET used_count = used_count + 1, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $discountId]);
    }
}
