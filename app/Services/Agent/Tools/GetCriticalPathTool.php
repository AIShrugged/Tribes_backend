<?php

namespace App\Services\Agent\Tools;

use App\Models\User;
use App\Services\CriticalPath\CriticalPathService;

class GetCriticalPathTool implements ToolInterface
{
    public function __construct(
        private readonly User $user,
        private readonly CriticalPathService $criticalPathService,
        private readonly ?int $organizationId = null,
        private readonly ?int $teamId = null,
    ) {}

    public function getName(): string
    {
        return 'get_critical_path';
    }

    public function getDescription(): string
    {
        return 'Возвращает предварительно вычисленный граф критического пути (CPM) для команды или организации. '
             .'Каждая нода содержит: ES (раннее начало), EF (раннее окончание), LS (позднее начало), LF (позднее окончание), '
             .'slack (резерв времени в рабочих днях), is_critical (на критическом пути). '
             .'Используй для ответов на вопросы: «что блокирует проект?», «какие задачи без резерва?», '
             .'«каков критический путь к завершению?». '
             .'Граф обновляется автоматически при изменении задач. '
             .'Если status=computing — данные ещё вычисляются. status=ready — граф актуален.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'scope' => [
                    'type' => 'string',
                    'enum' => ['team', 'organization'],
                    'description' => 'team — граф для команды, organization — граф для всей организации',
                ],
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'ID команды (опционально; если не указан — берётся из контекста сессии)',
                ],
            ],
            'required' => ['scope'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $scope = $parameters['scope'] ?? 'team';
        $teamId = isset($parameters['team_id']) ? (int) $parameters['team_id'] : $this->teamId;

        $resolvedTeamId = $scope === 'team' ? $teamId : null;
        $resolvedOrgId = $scope === 'organization' ? $this->organizationId : null;

        $graph = $this->criticalPathService->getGraphWithNodes($resolvedTeamId, $resolvedOrgId);

        if (! $graph) {
            return [
                'status' => 'not_found',
                'message' => 'Критический путь ещё не был вычислен. Используй rebuild для запуска.',
            ];
        }

        $nodes = $graph->nodes
            ->where('node_type', 'issue')
            ->map(fn ($node) => [
                'node_id' => $node->id,
                'issue_id' => $node->issue_id,
                'issue_name' => $node->issue?->name,
                'status' => $node->issue?->status,
                'priority' => $node->issue?->priority,
                'due_date' => $node->issue?->due_date?->toDateString(),
                'duration_days' => $node->duration_days,
                'early_start' => $node->early_start,
                'early_finish' => $node->early_finish,
                'late_start' => $node->late_start,
                'late_finish' => $node->late_finish,
                'slack' => $node->slack,
                'is_critical' => $node->is_critical,
            ])
            ->sortBy('early_start')
            ->values()
            ->all();

        $edges = $graph->edges->map(fn ($edge) => [
            'from_node_id' => $edge->from_node_id,
            'to_node_id' => $edge->to_node_id,
            'edge_type' => $edge->edge_type,
        ])->values()->all();

        $projectDuration = collect($nodes)->max('early_finish') ?? 0;

        return [
            'graph_id' => $graph->id,
            'status' => $graph->status,
            'computed_at' => $graph->computed_at?->toIso8601String(),
            'project_duration_days' => $projectDuration,
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }
}
