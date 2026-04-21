<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class OrderBriefingDTO
{
    public function __construct(
        public int $orderId,
        public string $title,
        public ?string $problemStatement,
        public ?string $generalObjective,
        public array $specificObjectives,
        public array $keywords,
        public array $extras,
        public array $attachments,
        public array $meta
    ) {}
}
