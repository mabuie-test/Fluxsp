<?php

declare(strict_types=1);

namespace App\Services;

final class DebitoLoggerService
{
    public function log(string $channel, array $data): void
    {
        $file = dirname(__DIR__, 2) . '/storage/logs/debito-' . date('Y-m-d') . '.log';
        file_put_contents($file, '[' . date('c') . '] ' . $channel . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
    }
}
