<?php

declare(strict_types=1);

namespace App\Services;

final class ExportService
{
    public function generatedPath(int $orderId): string
    {
        $base = dirname(__DIR__, 2) . '/' . ($_ENV['STORAGE_GENERATED_PATH'] ?? 'storage/generated');
        if (!is_dir($base)) {
            mkdir($base, 0775, true);
        }
        return $base . '/order-' . $orderId . '.docx';
    }
}
