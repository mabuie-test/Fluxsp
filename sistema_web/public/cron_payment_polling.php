<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$job = new \App\Jobs\PaymentPollingJob();
$count = $job->handle();
echo "Polling finalizado. Pagamentos verificados: {$count}\n";
