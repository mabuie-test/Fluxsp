<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AIProviderInterface;

final class AIOrchestrationService
{
    public function __construct(private readonly AIProviderInterface $provider = new OpenAIProvider()) {}

    public function run(array $prompts): array
    {
        $sections = [];
        foreach ($prompts as $prompt) {
            $raw = $this->provider->generate($prompt);
            $sections[] = $this->provider->refine($raw);
        }

        return $sections;
    }
}
