<?php

namespace App\Services\Agent\Tools;

use App\Models\TaskDigest;
use App\Models\User;
use Carbon\Carbon;

class GetDailyTaskDigestTool implements ToolInterface
{
    public function __construct(private readonly User $user)
    {
    }

    public function getName(): string
    {
        return 'get_daily_task_digest';
    }

    public function getDescription(): string
    {
        return 'Get the user\'s pre-generated daily task progress digest (Three Ps: progress, problems, priorities) '
             . 'with metrics snapshot. Read-only: this tool fetches what cron generated at 06:30, not a fresh LLM call. '
             . 'Returns {ready: false} if no digest exists for today yet. '
             . 'Use when the user asks "что у меня по работе сегодня", "покажи мой прогресс", "что главное на сегодня".';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date' => [
                    'type' => 'string',
                    'description' => 'Date in YYYY-MM-DD format. Defaults to today.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $dateStr = $parameters['date'] ?? null;
        $date = $dateStr ? Carbon::parse($dateStr) : Carbon::now();

        // Multi-org: prefer the first active organization
        $org = $this->user->organizations()->first();
        if (! $org) {
            return ['ready' => false, 'reason' => 'User is not a member of any organization'];
        }

        $digest = TaskDigest::query()
            ->where('user_id', $this->user->id)
            ->where('organization_id', $org->id)
            ->where('period_type', TaskDigest::PERIOD_DAILY)
            ->whereDate('period_start', $date->toDateString())
            ->first();

        if (! $digest || $digest->isExpired()) {
            return [
                'ready' => false,
                'reason' => 'Digest not yet generated for this date',
                'organization_id' => $org->id,
            ];
        }

        return [
            'ready' => true,
            'organization_id' => $org->id,
            'date' => $digest->period_start->toDateString(),
            'content' => $digest->content,
        ];
    }
}
