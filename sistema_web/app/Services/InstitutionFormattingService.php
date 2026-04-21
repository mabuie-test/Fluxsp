<?php

declare(strict_types=1);

namespace App\Services;

final class InstitutionFormattingService
{
    public function apply(array $sections, array $visualRules): array
    {
        return ['rules' => $visualRules, 'sections' => $sections];
    }
}
