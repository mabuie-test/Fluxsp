<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\OrderBriefingDTO;

final class RequirementInterpreterService
{
    public function interpret(array $order, array $requirements, array $attachments = []): OrderBriefingDTO
    {
        return new OrderBriefingDTO(
            (int) $order['id'],
            trim((string) $order['title_or_theme']),
            $order['problem_statement'] ?? null,
            $order['general_objective'] ?? null,
            json_decode($order['specific_objectives_json'] ?? '[]', true) ?: [],
            json_decode($order['keywords_json'] ?? '[]', true) ?: [],
            $requirements,
            $attachments,
            ['deadline_date' => $order['deadline_date'], 'complexity_level' => $order['complexity_level']]
        );
    }
}
