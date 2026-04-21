<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;

final class PaymentStatusPollingService
{
    public function __construct(
        private readonly PaymentRepository $paymentRepository = new PaymentRepository(),
        private readonly OrderRepository $orderRepository = new OrderRepository(),
        private readonly DebitoMpesaProvider $provider = new DebitoMpesaProvider(),
        private readonly DebitoStatusMapper $statusMapper = new DebitoStatusMapper(),
    ) {}

    public function run(): int
    {
        $updated = 0;
        $pending = $this->paymentRepository->findPendingForPolling();

        foreach ($pending as $payment) {
            if (empty($payment['external_reference'])) {
                continue;
            }

            $response = $this->provider->checkStatus($payment['external_reference']);
            $status = $this->statusMapper->map((string) ($response['status'] ?? 'failed'));

            $this->paymentRepository->updateStatus((int) $payment['id'], [
                'status' => $status,
                'provider_status' => $response['status'] ?? null,
                'status_message' => $response['message'] ?? null,
                'paid_at' => $status === 'paid' ? date('Y-m-d H:i:s') : null,
            ]);

            if ($status === 'paid') {
                $this->orderRepository->updateStatus((int) $payment['order_id'], 'queued');
            }
            if (in_array($status, ['failed', 'cancelled', 'expired'], true)) {
                $this->orderRepository->updateStatus((int) $payment['order_id'], 'awaiting_payment');
            }
            $updated++;
        }

        return $updated;
    }
}
