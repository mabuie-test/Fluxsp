<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class InvoiceService
{
    public function create(int $userId, int $orderId, float $amount, string $currency): int
    {
        $invoiceNumber = sprintf('%s-%s', $_ENV['INVOICE_PREFIX'] ?? 'MZA', date('YmdHis'));
        $db = Database::connection();
        $stmt = $db->prepare('INSERT INTO invoices (user_id,order_id,invoice_number,amount,currency,status,issued_at,created_at,updated_at) VALUES (:u,:o,:n,:a,:c,:s,NOW(),NOW(),NOW())');
        $stmt->execute(['u' => $userId, 'o' => $orderId, 'n' => $invoiceNumber, 'a' => $amount, 'c' => $currency, 's' => 'unpaid']);
        return (int) $db->lastInsertId();
    }
}
