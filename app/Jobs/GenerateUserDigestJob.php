<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\TaskDigest;
use App\Models\User;
use App\Services\Digest\TaskDigestService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches one digest generation per (user, organization) pair.
 *
 * Rate-limited via 'openrouter-digests' limiter to respect provider RPM caps.
 * Unique by (user, org, period_type, period_start) for the next hour to prevent
 * duplicate dispatches in cron+manual race.
 */
class GenerateUserDigestJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60;
    public int $uniqueFor = 3600;

    public function __construct(
        public int $userId,
        public int $organizationId,
        public string $periodType,
        public string $periodStartDate,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->userId}:{$this->organizationId}:{$this->periodType}:{$this->periodStartDate}";
    }

    public function middleware(): array
    {
        return [new RateLimited('openrouter-digests')];
    }

    public function handle(TaskDigestService $service): void
    {
        $user = User::find($this->userId);
        $org = Organization::find($this->organizationId);

        if (! $user || ! $org) {
            Log::warning('GenerateUserDigestJob: user or org not found, skipping', [
                'user_id' => $this->userId,
                'organization_id' => $this->organizationId,
            ]);
            return;
        }

        // Cold-start anti-fatigue: new user (<2 days old) with no activity → skip.
        if ($user->created_at !== null && $user->created_at->gt(now()->subDays(2))) {
            $hasIssues = $user->id !== null
                && \App\Models\Issue::query()->where('assignee_id', $user->id)->exists();
            if (! $hasIssues) {
                Log::info('GenerateUserDigestJob: cold-start user with no issues, skipping', [
                    'user_id' => $this->userId,
                ]);
                return;
            }
        }

        $service->generate(
            user: $user,
            org: $org,
            periodType: $this->periodType,
            periodStart: Carbon::parse($this->periodStartDate),
        );
    }
}
