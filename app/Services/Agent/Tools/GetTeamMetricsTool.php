<?php

namespace App\Services\Agent\Tools;

use App\Models\Team;
use App\Models\User;
use App\Services\Metrics\PerformanceMetricsService;

class GetTeamMetricsTool implements ToolInterface
{
    public function __construct(
        private readonly User $user,
        private readonly PerformanceMetricsService $metricsService,
    ) {}

    public function getName(): string
    {
        return 'get_team_metrics';
    }

    public function getDescription(): string
    {
        return 'Get aggregated performance metrics for a team plus per-member breakdown. '
             . 'Access: caller must be a team member, OR an organization manager of the team\'s organization. '
             . 'Returns aggregated team metrics + by_member map keyed by user_id. '
             . 'Use when a manager asks "как у команды [name] дела" or "покажи метрики команды".';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Team ID to query metrics for.',
                ],
                'period' => [
                    'type' => 'string',
                    'description' => 'Period over which to compute metrics. Default: this_week.',
                    'enum' => ['today', 'this_week', 'last_7_days'],
                ],
            ],
            'required' => ['team_id'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $teamId = (int) ($parameters['team_id'] ?? 0);
        $periodKey = $parameters['period'] ?? 'this_week';

        $team = Team::find($teamId);
        if (! $team) {
            return ['success' => false, 'error' => "Team {$teamId} not found"];
        }

        // Authorization: team member OR org manager
        if (! $this->user->isTeamMember($team) && ! $this->user->isOrganizationManager($team->organization_id)) {
            return ['success' => false, 'error' => 'You are not authorized to view this team\'s metrics'];
        }

        $period = match ($periodKey) {
            'today' => PerformanceMetricsService::today(),
            'last_7_days' => PerformanceMetricsService::last7Days(),
            default => PerformanceMetricsService::thisWeek(),
        };

        $teamMetrics = $this->metricsService->forTeam($team, $period);

        return [
            'success' => true,
            'team_id' => $team->id,
            'team_name' => $team->name,
            'period' => $periodKey,
            'period_from' => $period['from']->toDateString(),
            'period_to' => $period['to']->toDateString(),
            'aggregated' => $teamMetrics['aggregated'],
            'by_member' => $teamMetrics['by_member'],
        ];
    }
}
