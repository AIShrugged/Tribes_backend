<?php

namespace App\Services\Agent\Tools;

use App\Models\TaskDigest;
use App\Models\User;
use Carbon\Carbon;

class GetWeeklyTaskDigestTool implements ToolInterface
{
    public function __construct(private readonly User $user)
    {
    }

    public function getName(): string
    {
        return 'get_weekly_task_digest';
    }

    public function getDescription(): string
    {
        return 'Get the user\'s pre-generated weekly task digest (managers only in v1). '
             . 'Returns {ready: false} if no weekly digest exists for the given week. '
             . 'Use when the manager asks about weekly trends or "что показал weekly digest".';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'week_start' => [
                    'type' => 'string',
                    'description' => 'Monday of the week in YYYY-MM-DD format. Defaults to current week.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $weekStr = $parameters['week_start'] ?? null;
        // Normalize to Monday of the given (or current) week — task_digests.period_start
        // is always Monday, so any non-Monday input would miss the row.
        $weekStart = $weekStr
            ? Carbon::parse($weekStr)->startOfWeek(Carbon::MONDAY)
            : Carbon::now()->startOfWeek(Carbon::MONDAY);

        $org = $this->user->organizations()->first();
        if (! $org) {
            return ['ready' => false, 'reason' => 'User is not a member of any organization'];
        }

        $digest = TaskDigest::query()
            ->where('user_id', $this->user->id)
            ->where('organization_id', $org->id)
            ->where('period_type', TaskDigest::PERIOD_WEEKLY)
            ->whereDate('period_start', $weekStart->toDateString())
            ->first();

        if (! $digest || $digest->isExpired()) {
            return [
                'ready' => false,
                'reason' => 'Weekly digest not generated for this week',
                'organization_id' => $org->id,
            ];
        }

        return [
            'ready' => true,
            'organization_id' => $org->id,
            'week_start' => $digest->period_start->toDateString(),
            'content' => $digest->content,
        ];
    }
}
