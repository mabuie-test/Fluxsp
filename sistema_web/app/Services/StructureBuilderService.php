<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ResolvedRuleSetDTO;

final class StructureBuilderService
{
    public function build(array $workType, ResolvedRuleSetDTO $rules): array
    {
        if (!empty($rules->structureRules)) {
            return $rules->structureRules;
        }

        return [
            ['code' => 'intro', 'title' => 'Introdução', 'required' => true],
            ['code' => 'desenvolvimento', 'title' => 'Desenvolvimento', 'required' => true],
            ['code' => 'conclusao', 'title' => 'Conclusão', 'required' => true],
            ['code' => 'referencias', 'title' => 'Referências', 'required' => true],
        ];
    }
}
