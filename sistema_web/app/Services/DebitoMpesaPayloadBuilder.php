<?php

declare(strict_types=1);

namespace App\Services;

final class DebitoMpesaPayloadBuilder
{
    public function __construct(private readonly MpesaMsisdnValidator $validator = new MpesaMsisdnValidator()) {}

    public function build(array $data): array
    {
        $msisdn = $this->validator->sanitize((string) $data['msisdn']);
        $amount = round((float) $data['amount'], 2);

        return [
            'amount' => $amount,
            'currency' => $_ENV['DEBITO_CURRENCY'] ?? 'MZN',
            'msisdn' => $msisdn,
            'reference' => $data['reference'],
            'reference_description' => $data['reference_description'] ?? 'Pagamento Moz Acad',
            'callback_url' => $_ENV['DEBITO_CALLBACK_URL'] ?: null,
        ];
    }
}
