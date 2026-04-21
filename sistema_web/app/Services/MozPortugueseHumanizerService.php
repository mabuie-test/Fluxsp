<?php

declare(strict_types=1);

namespace App\Services;

final class MozPortugueseHumanizerService
{
    public function humanize(array $sections, string $profile = 'academic_humanized'): array
    {
        $map = ['você' => 'o estudante', 'trabalho' => 'trabalho académico'];
        return array_map(static function (string $text) use ($map): string {
            return strtr($text, $map);
        }, $sections);
    }
}
