<?php

namespace App\Services\Agent\Tools;

use App\Models\User;
use App\Services\Metrics\PerformanceMetricsService;

class GetUserMetricsTool implements ToolInterface
{
    public function __construct(
        private readonly User $user,
        private readonly PerformanceMetricsService $metricsService,
    ) {}

    public function getName(): string
    {
        return 'get_user_metrics';
    }

    public function getDescription(): string
    {
        return 'Get performance metrics for the current user over a specified period. '
             . 'Returns: done (tasks closed in period), in_progress (currently active), '
             . 'overdue (active with past due_date), velocity_week (done in last 7 days), '
             . 'avg_lead_time_days (avg time from creation to close, in days). '
             . 'Use when the user asks "сколько задач я закрыл", "как у меня дела", or similar self-progress queries.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period' => [
                    'type' => 'string',
                    'description' => 'Period over which to compute metrics. Default: this_week.',
                    'enum' => ['today', 'this_week', 'last_7_days'],
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $periodKey = $parameters['period'] ?? 'this_week';

        $period = match ($periodKey) {
            'today' => PerformanceMetricsService::today(),
            'last_7_days' => PerformanceMetricsService::last7Days(),
            default => PerformanceMetricsService::thisWeek(),
        };

        $metrics = $this->metricsService->forUser($this->user, $period);

        return [
            'success' => true,
            'period' => $periodKey,
            'period_from' => $period['from']->toDateString(),
            'period_to' => $period['to']->toDateString(),
            'metrics' => $metrics,
        ];
    }
}
