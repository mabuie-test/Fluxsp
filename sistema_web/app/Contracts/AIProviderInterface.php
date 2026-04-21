<?php

declare(strict_types=1);

namespace App\Contracts;

interface AIProviderInterface
{
    public function generate(string $prompt, array $context = []): string;

    public function refine(string $content, array $rules = []): string;
}
