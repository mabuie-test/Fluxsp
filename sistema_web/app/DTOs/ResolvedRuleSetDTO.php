<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ResolvedRuleSetDTO
{
    public function __construct(
        public array $visualRules,
        public array $referenceRules,
        public array $structureRules,
        public array $meta
    ) {}
}
