<?php

declare(strict_types=1);

namespace App\Repositories;

final class OrderRepository extends BaseRepository
{
    public function create(array $data): int
    {
        $sql = 'INSERT INTO orders (user_id,institution_id,course_id,discipline_id,academic_level_id,work_type_id,title_or_theme,subtitle,problem_statement,general_objective,specific_objectives_json,hypothesis,keywords_json,target_pages,complexity_level,deadline_date,notes,status,created_at,updated_at)
                VALUES (:user_id,:institution_id,:course_id,:discipline_id,:academic_level_id,:work_type_id,:title_or_theme,:subtitle,:problem_statement,:general_objective,:specific_objectives_json,:hypothesis,:keywords_json,:target_pages,:complexity_level,:deadline_date,:notes,:status,NOW(),NOW())';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($data);

        return (int) $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function updateStatus(int $orderId, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE orders SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $orderId]);
    }
}
