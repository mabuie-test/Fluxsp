<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\PricingBreakdownDTO;

final class PricingService
{
    public function __construct(
        private readonly PricingConfig $config = new PricingConfig(),
        private readonly ExtraPricingService $extraPricingService = new ExtraPricingService(),
        private readonly UserDiscountResolverService $userDiscountResolver = new UserDiscountResolverService(),
        private readonly DiscountService $discountService = new DiscountService(),
    ) {}

    public function calculate(array $payload): PricingBreakdownDTO
    {
        $base = $this->config->basePriceByWorkTypeSlug($payload['work_type_slug']);
        $includedPages = $this->config->includedPages();
        $extraPagesCount = max(0, ((int) $payload['target_pages']) - $includedPages);
        $extraPagesAmount = $extraPagesCount * $this->config->perPage();

        $levelMultiplier = (float) ($payload['academic_level_multiplier'] ?? 1.0);
        $complexityMultiplier = (float) ($payload['complexity_multiplier'] ?? 1.0);
        $urgencyMultiplier = (float) ($payload['urgency_multiplier'] ?? 1.0);

        $extrasAmount = $this->extraPricingService->calculate($payload['extras'] ?? []);
        $humanReviewFee = !empty($payload['requires_human_review']) ? $this->config->humanReviewMonografiaFee() : 0.0;

        $subtotal = (($base + $extraPagesAmount) * $levelMultiplier * $complexityMultiplier * $urgencyMultiplier) + $extrasAmount + $humanReviewFee;

        $selectedDiscount = $this->userDiscountResolver->resolve((int) $payload['user_id'], (int) $payload['work_type_id']);
        $userDiscount = $this->discountService->apply($selectedDiscount, $subtotal, $payload['extras'] ?? []);

        $couponDiscount = 0.0;
        if (!empty($payload['coupon_percent'])) {
            $couponDiscount = $subtotal * ((float) $payload['coupon_percent'] / 100);
        }

        $total = max($this->config->minimumAmount(), $subtotal - $couponDiscount - $userDiscount['amount']);

        return new PricingBreakdownDTO(
            round($base, 2),
            $includedPages,
            $extraPagesCount,
            round($extraPagesAmount, 2),
            $levelMultiplier,
            $complexityMultiplier,
            $urgencyMultiplier,
            round($extrasAmount, 2),
            round($humanReviewFee, 2),
            round($couponDiscount, 2),
            round($userDiscount['amount'], 2),
            round($total, 2),
            ['user_discount' => $selectedDiscount]
        );
    }
}
