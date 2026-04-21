<?php

declare(strict_types=1);

namespace App\Services;

final class CitationFormatterService
{
    public function formatReferences(array $references, string $style = 'ABNT'): array
    {
        return array_map(static fn (string $r) => "{$style}: {$r}", $references);
    }
}
