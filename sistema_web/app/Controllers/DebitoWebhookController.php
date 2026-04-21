<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\DebitoLoggerService;

final class DebitoWebhookController extends BaseController
{
    public function handle(): void
    {
        $payload = json_decode(file_get_contents('php://input'), true) ?? [];
        (new DebitoLoggerService())->log('webhook', $payload);
        // webhook é complementar; polling continua principal
        $this->json(['received' => true]);
    }
}
