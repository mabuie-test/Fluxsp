<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PaymentStatusPollingService;

final class PaymentPollingJob
{
    public function handle(): int
    {
        return (new PaymentStatusPollingService())->run();
    }
}
