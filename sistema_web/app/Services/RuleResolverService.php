<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ResolvedRuleSetDTO;
use App\Core\Database;

final class RuleResolverService
{
    public function resolve(int $institutionId, int $workTypeId, int $levelId): ResolvedRuleSetDTO
    {
        $db = Database::connection();
        $institutionRules = $db->query("SELECT * FROM institution_rules WHERE institution_id = {$institutionId} LIMIT 1")->fetch() ?: [];
        $workTypeRules = $db->query("SELECT * FROM institution_work_type_rules WHERE institution_id = {$institutionId} AND work_type_id = {$workTypeId} LIMIT 1")->fetch() ?: [];
        $levelRules = $db->query("SELECT * FROM academic_level_rules WHERE academic_level_id = {$levelId} LIMIT 1")->fetch() ?: [];

        return new ResolvedRuleSetDTO(
            array_filter([$institutionRules, json_decode($workTypeRules['custom_visual_rules_json'] ?? '[]', true), $levelRules]),
            [
                'references_style' => $institutionRules['references_style'] ?? 'ABNT',
                'citation_profile_id' => $institutionRules['citation_profile_id'] ?? null,
            ],
            json_decode($workTypeRules['custom_structure_json'] ?? '[]', true),
            ['institution_id' => $institutionId, 'work_type_id' => $workTypeId, 'level_id' => $levelId]
        );
    }
}
