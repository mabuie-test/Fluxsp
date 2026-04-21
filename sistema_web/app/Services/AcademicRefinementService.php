<?php

declare(strict_types=1);

namespace App\Services;

final class AcademicRefinementService
{
    public function refine(array $sections): array
    {
        return array_map(static fn (string $s) => preg_replace('/\s+/', ' ', trim($s)), $sections);
    }
}
