<?php

declare(strict_types=1);

namespace App\Repositories;

final class PaymentRepository extends BaseRepository
{
    public function create(array $data): int
    {
        $sql = 'INSERT INTO payments (user_id,order_id,invoice_id,provider,method,amount,currency,msisdn,status,internal_reference,external_reference,provider_transaction_id,provider_status,status_message,created_at,updated_at)
                VALUES (:user_id,:order_id,:invoice_id,:provider,:method,:amount,:currency,:msisdn,:status,:internal_reference,:external_reference,:provider_transaction_id,:provider_status,:status_message,NOW(),NOW())';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($data);

        return (int) $this->db->lastInsertId();
    }

    public function findPendingForPolling(int $limit = 20): array
    {
        $stmt = $this->db->prepare("SELECT * FROM payments WHERE status IN ('pending', 'processing', 'pending_confirmation') ORDER BY id ASC LIMIT :limit");
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function updateStatus(int $id, array $data): void
    {
        $sql = 'UPDATE payments SET status=:status,provider_status=:provider_status,status_message=:status_message,paid_at=:paid_at,updated_at=NOW() WHERE id=:id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($data + ['id' => $id]);
    }
}
