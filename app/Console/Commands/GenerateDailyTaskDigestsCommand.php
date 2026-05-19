<?php

namespace App\Console\Commands;

use App\Jobs\GenerateUserDigestJob;
use App\Models\TaskDigest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateDailyTaskDigestsCommand extends Command
{
    protected $signature = 'tasks:generate-daily-digests
        {--test-user= : Only dispatch for this user ID}
        {--dry-run : Log dispatch targets without actually queueing}';

    protected $description = 'Dispatch daily task digest generation per (user, organization). Pre-generates content for morning brief.';

    public function handle(): int
    {
        if (! config('features.enable_daily_digest', false)) {
            $this->info('Daily digest feature is disabled (ENABLE_DAILY_DIGEST=false). Skipping.');
            return self::SUCCESS;
        }

        $ceiling = (int) env('MAX_DIGEST_USERS_PER_RUN', 50);
        $testUser = $this->option('test-user');
        $dryRun = (bool) $this->option('dry-run');
        $today = Carbon::now()->startOfDay();

        $query = User::query()
            ->with('organizations')
            ->whereHas('telegramUser')
            ->whereHas('organizations'); // H1: only active org members

        if ($testUser) {
            $query->where('id', (int) $testUser);
        }

        $count = 0;
        $dispatched = 0;
        $query->chunkById(200, function ($users) use (&$count, &$dispatched, $ceiling, $dryRun, $today) {
            foreach ($users as $user) {
                foreach ($user->organizations as $org) {
                    $count++;
                    if ($count > $ceiling) {
                        throw new \RuntimeException("MAX_DIGEST_USERS_PER_RUN ceiling exceeded: {$ceiling}");
                    }

                    if ($dryRun) {
                        $this->line(sprintf('DRY: user=%d (%s) org=%d (%s)', $user->id, $user->name, $org->id, $org->name));
                        continue;
                    }

                    GenerateUserDigestJob::dispatch(
                        userId: $user->id,
                        organizationId: $org->id,
                        periodType: TaskDigest::PERIOD_DAILY,
                        periodStartDate: $today->toDateString(),
                    );
                    $dispatched++;
                }
            }
        });

        $this->info(sprintf(
            'Daily digest: evaluated %d (user, org) pairs, dispatched %d jobs%s.',
            $count,
            $dispatched,
            $dryRun ? ' (dry-run, none queued)' : '',
        ));

        return self::SUCCESS;
    }
}
