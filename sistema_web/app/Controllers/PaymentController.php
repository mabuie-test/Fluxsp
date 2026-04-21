<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\InvoiceService;
use App\Services\PaymentService;

final class PaymentController extends BaseController
{
    public function initiateMpesa(): void
    {
        $invoiceId = (new InvoiceService())->create((int) $_POST['user_id'], (int) $_POST['order_id'], (float) $_POST['amount'], $_ENV['PRICING_CURRENCY'] ?? 'MZN');
        $result = (new PaymentService())->initiateMpesa([
            'user_id' => (int) $_POST['user_id'],
            'order_id' => (int) $_POST['order_id'],
            'invoice_id' => $invoiceId,
            'amount' => (float) $_POST['amount'],
            'msisdn' => (string) $_POST['msisdn'],
        ]);

        $this->json($result, 201);
    }
}
