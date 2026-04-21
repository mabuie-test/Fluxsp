<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PricingBreakdownDTO
{
    public function __construct(
        public float $baseAmount,
        public int $includedPages,
        public int $extraPagesCount,
        public float $extraPagesAmount,
        public float $academicLevelMultiplier,
        public float $complexityMultiplier,
        public float $urgencyMultiplier,
        public float $extrasAmount,
        public float $humanReviewFee,
        public float $couponDiscountAmount,
        public float $userDiscountAmount,
        public float $finalTotal,
        public array $appliedDiscounts = []
    ) {}
}
