<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PaymentRepository;

final class PaymentService
{
    public function __construct(
        private readonly DebitoMpesaProvider $provider = new DebitoMpesaProvider(),
        private readonly DebitoMpesaPayloadBuilder $payloadBuilder = new DebitoMpesaPayloadBuilder(),
        private readonly PaymentRepository $paymentRepository = new PaymentRepository(),
    ) {}

    public function initiateMpesa(array $paymentData): array
    {
        $internalReference = sprintf('%s-%s', $_ENV['PAYMENT_REFERENCE_PREFIX'] ?? 'PAY', date('YmdHis'));
        $payload = $this->payloadBuilder->build([
            'amount' => $paymentData['amount'],
            'msisdn' => $paymentData['msisdn'],
            'reference' => $internalReference,
            'reference_description' => 'Pagamento pedido #' . $paymentData['order_id'],
        ]);

        $response = $this->provider->initiate($payload);

        $paymentId = $this->paymentRepository->create([
            'user_id' => $paymentData['user_id'],
            'order_id' => $paymentData['order_id'],
            'invoice_id' => $paymentData['invoice_id'],
            'provider' => 'debito',
            'method' => 'mpesa_c2b',
            'amount' => $paymentData['amount'],
            'currency' => $_ENV['PRICING_CURRENCY'] ?? 'MZN',
            'msisdn' => $payload['msisdn'],
            'status' => 'pending_confirmation',
            'internal_reference' => $internalReference,
            'external_reference' => $response['reference'] ?? null,
            'provider_transaction_id' => $response['transaction_id'] ?? null,
            'provider_status' => $response['status'] ?? 'pending',
            'status_message' => $response['message'] ?? 'Aguardando confirmação',
        ]);

        return ['payment_id' => $paymentId, 'internal_reference' => $internalReference, 'provider_response' => $response];
    }
}
