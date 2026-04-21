<?php

use App\Controllers\DebitoWebhookController;
use App\Controllers\PaymentController;

return [
    ['POST', '/payments/mpesa/initiate', [PaymentController::class, 'initiateMpesa']],
    ['POST', '/webhooks/debito', [DebitoWebhookController::class, 'handle']],
];
