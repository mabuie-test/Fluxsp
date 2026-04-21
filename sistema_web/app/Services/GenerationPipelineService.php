<?php

declare(strict_types=1);

namespace App\Services;

final class GenerationPipelineService
{
    public function __construct(
        private readonly RequirementInterpreterService $requirementInterpreter = new RequirementInterpreterService(),
        private readonly RuleResolverService $ruleResolver = new RuleResolverService(),
        private readonly StructureBuilderService $structureBuilder = new StructureBuilderService(),
        private readonly PromptComposerService $promptComposer = new PromptComposerService(),
        private readonly AIOrchestrationService $aiOrchestration = new AIOrchestrationService(),
        private readonly AcademicRefinementService $academicRefinement = new AcademicRefinementService(),
        private readonly MozPortugueseHumanizerService $humanizer = new MozPortugueseHumanizerService(),
        private readonly InstitutionFormattingService $formatter = new InstitutionFormattingService(),
        private readonly ExportService $exportService = new ExportService(),
        private readonly DocxAssemblyService $docxAssembly = new DocxAssemblyService(),
        private readonly HumanReviewQueueService $humanQueue = new HumanReviewQueueService(),
    ) {}

    public function generate(array $order, array $requirements, array $workType): string
    {
        $briefing = $this->requirementInterpreter->interpret($order, $requirements);
        $rules = $this->ruleResolver->resolve((int)$order['institution_id'], (int)$order['work_type_id'], (int)$order['academic_level_id']);
        $structure = $this->structureBuilder->build($workType, $rules);
        $prompts = $this->promptComposer->compose($briefing, $structure, $rules->visualRules);

        $sections = $this->aiOrchestration->run($prompts);
        $sections = $this->academicRefinement->refine($sections);
        $sections = $this->humanizer->humanize($sections);
        $formatted = $this->formatter->apply($sections, $rules->visualRules);

        $path = $this->exportService->generatedPath((int) $order['id']);
        $this->docxAssembly->assemble($briefing->title, $formatted['sections'], $path);

        if (!empty($workType['requires_human_review'])) {
            $this->humanQueue->enqueue((int) $order['id']);
        }

        return $path;
    }
}
