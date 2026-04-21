<?php

declare(strict_types=1);

namespace App\Services;

final class ExtraPricingService
{
    public function calculate(array $extras): float
    {
        $map = [
            'needs_institution_cover' => (float) ($_ENV['PRICING_EXTRA_CAPA_PERSONALIZADA'] ?? 200),
            'needs_bilingual_abstract' => (float) ($_ENV['PRICING_EXTRA_ABSTRACT_BILINGUE'] ?? 300),
            'needs_methodology_review' => (float) ($_ENV['PRICING_EXTRA_REVISAO_METODOLOGICA'] ?? 500),
            'needs_humanized_revision' => (float) ($_ENV['PRICING_EXTRA_REVISAO_HUMANIZADA'] ?? 400),
            'needs_slides' => (float) ($_ENV['PRICING_EXTRA_APRESENTACAO_SLIDES'] ?? 800),
            'needs_defense_summary' => (float) ($_ENV['PRICING_EXTRA_RESUMO_DEFESA'] ?? 450),
        ];

        $total = 0.0;
        foreach ($map as $key => $value) {
            if (!empty($extras[$key])) {
                $total += $value;
            }
        }
        return $total;
    }
}
