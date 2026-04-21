<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AIProviderInterface;

final class OpenAIProvider implements AIProviderInterface
{
    public function generate(string $prompt, array $context = []): string
    {
        return "[Conteúdo gerado assistido] {$prompt}";
    }

    public function refine(string $content, array $rules = []): string
    {
        return trim($content) . "\n\n[Refinado com regras académicas]";
    }
}
