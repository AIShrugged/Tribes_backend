<?php

namespace App\Services\Agent\Tools;

use App\Enums\ArtifactType;
use App\Enums\MethodologySchemeVersion;
use App\Models\Chat;
use App\Models\Methodology;
use App\Models\User;
use App\Services\Artifact\ArtifactStateService;

class SaveMethodologyTool implements ToolInterface
{
    public function __construct(
        private readonly User $user,
        private readonly Chat $chat,
        private readonly ArtifactStateService $artifactStateService,
    ) {
    }

    public function getName(): string
    {
        return 'save_methodology';
    }

    public function getDescription(): string
    {
        return 'Saves a finalized methodology to the database. '
            . 'Use only when the user explicitly confirms they are satisfied with the criteria. '
            . 'Requires the user to be a manager of the target organization. '
            . 'Optionally assigns the methodology to specified teams.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['name', 'methodology_text', 'artifact_id', 'organization_id'],
            'properties' => [
                'name' => [
                    'type'        => 'string',
                    'description' => 'Name of the methodology.',
                ],
                'methodology_text' => [
                    'type'        => 'string',
                    'description' => 'Full text description of the methodology.',
                ],
                'artifact_id' => [
                    'type'        => 'string',
                    'description' => 'ID of the methodology_criteria artifact containing the finalized criteria.',
                ],
                'organization_id' => [
                    'type'        => 'integer',
                    'description' => 'ID of the organization to create the methodology for.',
                ],
                'team_ids' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'integer'],
                    'description' => 'Optional list of team IDs to assign this methodology to.',
                ],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $name             = $parameters['name'] ?? null;
        $methodologyText  = $parameters['methodology_text'] ?? null;
        $artifactId       = $parameters['artifact_id'] ?? null;
        $organizationId   = $parameters['organization_id'] ?? null;
        $teamIds          = $parameters['team_ids'] ?? [];

        if (! $name || ! $methodologyText || ! $artifactId || ! $organizationId) {
            return [
                'success' => false,
                'error'   => 'Parameters `name`, `methodology_text`, `artifact_id`, and `organization_id` are required.',
            ];
        }

        if (! $this->user->isOrganizationManager($organizationId)) {
            return [
                'success' => false,
                'error'   => 'Only a manager of the organization can create methodologies.',
            ];
        }

        $state = $this->artifactStateService->loadState($this->chat);

        if (! isset($state['artifacts'][$artifactId])) {
            return [
                'success' => false,
                'error'   => "Artifact '{$artifactId}' not found in this chat.",
            ];
        }

        $artifact = $state['artifacts'][$artifactId];

        if ($artifact['type'] !== ArtifactType::MethodologyCriteria->value) {
            return [
                'success' => false,
                'error'   => "Artifact '{$artifactId}' is not a methodology_criteria artifact.",
            ];
        }

        $scheme = $this->convertBlocksToScheme($artifact['data']);

        $methodology = Methodology::create([
            'name'           => $name,
            'text'           => $methodologyText,
            'scheme'         => json_encode($scheme),
            'scheme_version' => MethodologySchemeVersion::VER_1->value,
            'organization_id' => $organizationId,
            'is_default'     => false,
        ]);

        $teamsAssigned = 0;

        if (! empty($teamIds)) {
            $methodology->syncTeams($teamIds);
            $teamsAssigned = count($teamIds);
        }

        return [
            'success'        => true,
            'methodology_id' => $methodology->id,
            'name'           => $methodology->name,
            'teams_assigned' => $teamsAssigned,
        ];
    }

    /**
     * Convert the block-based artifact data into the scheme format
     * expected by FollowupService (total/metrics/conclusion).
     */
    private function convertBlocksToScheme(array $data): array
    {
        $blocks = $data['blocks'] ?? [];

        $metrics    = [];
        $totalMax   = 0;
        $conclusion = [];

        foreach ($blocks as $block) {
            $type = $block['type'] ?? null;

            if ($type === 'scoring_table') {
                foreach ($block['rows'] ?? [] as $row) {
                    $maxValue = is_numeric(end($row)) ? (int) end($row) : 0;
                    $totalMax += $maxValue;

                    $metrics[] = [
                        'display_name'            => implode(' — ', array_slice($row, 0, -1)),
                        'frontend_component_type' => 'linear-progress',
                        'min_value'               => 0,
                        'current_value'           => 0,
                        'max_value'               => $maxValue,
                    ];
                }
            }

            if ($type === 'text_list') {
                $conclusion[] = [
                    'display_name' => $block['title'] ?? '',
                    'value'        => $block['items'] ?? [],
                ];
            }
        }

        return [
            'total' => [
                'display_name'            => 'Общий балл',
                'frontend_component_type' => 'chart-donut',
                'min_value'               => 0,
                'current_value'           => 0,
                'max_value'               => $totalMax,
            ],
            'metrics'    => $metrics,
            'conclusion' => [
                'display_name'            => 'Результаты',
                'frontend_component_type' => 'common-list',
                'value'                   => $conclusion,
            ],
        ];
    }
}
