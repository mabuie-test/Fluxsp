<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\OrderBriefingDTO;

final class PromptComposerService
{
    public function compose(OrderBriefingDTO $briefing, array $structure, array $resolvedRules): array
    {
        $prompts = [];
        foreach ($structure as $section) {
            $prompts[] = sprintf(
                "Escreva a secção '%s' em português académico moçambicano com foco no tema: %s.",
                $section['title'],
                $briefing->title
            );
        }

        return $prompts;
    }
}
