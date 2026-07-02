<?php

namespace App\Services\Agent\Tools;

use App\Models\Issue;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class BuildDailyPlanTool implements ToolInterface
{
    public function __construct(
        private readonly User $user,
        private readonly ?int $organizationId = null,
        private readonly ?int $teamId = null,
    ) {}

    public function getName(): string
    {
        return 'build_daily_plan';
    }

    public function getDescription(): string
    {
        return 'Сформировать дневной план задач по критическому пути. '
             .'Возвращает открытые задачи, сгруппированные по командам, отсортированные по приоритету и дедлайну. '
             .'scope=personal — задачи пользователя (+ контекст задач команды); scope=team — все задачи команды по исполнителям. '
             .'Используй результат для анализа критического пути: инферируй блокеры из описаний задач, '
             .'раздели на «Сегодня» (срочные/высокий приоритет/близкий дедлайн) и «Остальное». '
             .'Формат ответа: «Сегодня: (1) задача — причина; (2) задача. Остальное — завтра: …»';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'scope' => [
                    'type' => 'string',
                    'enum' => ['personal', 'team'],
                    'description' => 'personal — задачи пользователя (+ контекст команды), team — все задачи команды',
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
        $scope = $parameters['scope'] ?? 'personal';
        $teamId = isset($parameters['team_id']) ? (int) $parameters['team_id'] : $this->teamId;
        $today = Carbon::today();

        if ($scope === 'team') {
            $issues = $this->baseQuery()
                ->when($teamId, fn (Builder $q) => $q->where('team_id', $teamId))
                ->when(! $teamId && $this->organizationId, fn (Builder $q) => $q->where('organization_id', $this->organizationId))
                ->limit(50)
                ->get();

            return [
                'scope' => 'team',
                'date' => $today->toDateString(),
                'issues' => $issues->map(fn (Issue $i) => $this->formatIssue($i, $today))->values()->all(),
            ];
        }

        $myIssues = $this->baseQuery()
            ->where('assignee_id', $this->user->id)
            ->limit(30)
            ->get();

        $teamContext = [];
        if ($teamId) {
            $teamContext = $this->baseQuery()
                ->where('team_id', $teamId)
                ->where('assignee_id', '!=', $this->user->id)
                ->limit(20)
                ->get()
                ->map(fn (Issue $i) => $this->formatIssue($i, $today))
                ->values()
                ->all();
        }

        return [
            'scope' => 'personal',
            'user' => $this->user->name,
            'date' => $today->toDateString(),
            'issues' => $myIssues->map(fn (Issue $i) => $this->formatIssue($i, $today))->values()->all(),
            'team_context' => $teamContext,
        ];
    }

    private function baseQuery(): Builder
    {
        return Issue::query()
            ->where('status', '!=', 'done')
            ->with([
                'assignee:id,name',
                'team:id,name',
                'blockedBy:id,name',
                'blocking:id,name',
            ])
            ->select(['id', 'code', 'number', 'name', 'description', 'priority', 'due_date', 'status', 'assignee_id', 'team_id'])
            ->orderBy('priority', 'desc')
            ->orderByRaw('due_date IS NULL ASC')
            ->orderBy('due_date', 'asc');
    }

    private function formatIssue(Issue $issue, Carbon $today): array
    {
        $daysUntilDue = null;
        $overdue = false;

        if ($issue->due_date) {
            $daysUntilDue = (int) $today->diffInDays($issue->due_date, false);
            $overdue = $daysUntilDue < 0;
        }

        $blockedBy = $issue->blockedBy->map(fn (Issue $b) => ['id' => $b->id, 'name' => $b->name])->values()->all();
        $blocking = $issue->blocking->map(fn (Issue $b) => ['id' => $b->id, 'name' => $b->name])->values()->all();
        $frontendUrl = rtrim(config('app.frontend_url'), '/');

        return [
            'id' => $issue->id,
            'code' => $issue->code,
            'number' => $issue->number,
            'name' => $issue->name,
            'url' => "{$frontendUrl}/dashboard/issues/{$issue->id}",
            'priority' => $this->priorityLabel($issue->priority),
            'priority_value' => $issue->priority,
            'due_date' => $issue->due_date?->toDateString(),
            'overdue' => $overdue,
            'days_until_due' => $daysUntilDue,
            'assignee' => $issue->assignee?->name,
            'team' => $issue->team?->name,
            'description_snippet' => $issue->description ? mb_substr($issue->description, 0, 200) : null,
            'blocked_by' => $blockedBy,
            'blocking' => $blocking,
        ];
    }

    private function priorityLabel(int $value): string
    {
        return match (true) {
            $value >= Issue::PRIORITY_CRITICAL => 'CRITICAL',
            $value >= Issue::PRIORITY_HIGH => 'HIGH',
            $value >= Issue::PRIORITY_NORMAL => 'NORMAL',
            $value >= Issue::PRIORITY_LOW => 'LOW',
            default => 'MINIMAL',
        };
    }
}
