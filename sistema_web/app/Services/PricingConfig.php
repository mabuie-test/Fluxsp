<?php

declare(strict_types=1);

namespace App\Services;

final class PricingConfig
{
    public function basePriceByWorkTypeSlug(string $slug): float
    {
        $map = [
            'trabalho-pesquisa' => (float) ($_ENV['PRICING_BASE_TRABALHO_PESQUISA'] ?? 800),
            'projecto-pesquisa' => (float) ($_ENV['PRICING_BASE_PROJECTO_PESQUISA'] ?? 1500),
            'monografia' => (float) ($_ENV['PRICING_BASE_MONOGRAFIA'] ?? 4500),
            'relatorio-estagio' => (float) ($_ENV['PRICING_BASE_RELATORIO_ESTAGIO'] ?? 2000),
            'artigo-cientifico' => (float) ($_ENV['PRICING_BASE_ARTIGO_CIENTIFICO'] ?? 1200),
        ];

        return $map[$slug] ?? 1000.0;
    }

    public function perPage(): float { return (float) ($_ENV['PRICING_PER_PAGE_DEFAULT'] ?? 40); }
    public function includedPages(): int { return (int) ($_ENV['PRICING_INCLUDED_PAGES_DEFAULT'] ?? 10); }
    public function minimumAmount(): float { return (float) ($_ENV['PRICING_MIN_ORDER_AMOUNT'] ?? 500); }
    public function humanReviewMonografiaFee(): float { return (float) ($_ENV['PRICING_HUMAN_REVIEW_MONOGRAFIA'] ?? 1500); }
}
