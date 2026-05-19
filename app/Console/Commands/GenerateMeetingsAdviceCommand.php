<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Digest\MeetingsAdviceService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Runs at 08:30 — after `agenda:generate --all-today` at 07:30 — to generate
 * per-meeting preparation advice for each (user, organization). Morning brief
 * at 09:00 then reads `task_digests.content[meetings_advice]` and renders.
 */
class GenerateMeetingsAdviceCommand extends Command
{
    protected $signature = 'tasks:generate-meetings-advice
        {--test-user= : Only process this user ID}
        {--dry-run : Log targets without generating}';

    protected $description = 'Generate per-meeting AI preparation advice for users\' morning brief.';

    public function handle(MeetingsAdviceService $service): int
    {
        if (! config('features.enable_daily_digest', false)) {
            $this->info('Daily digest feature disabled — skipping meetings advice.');
            return self::SUCCESS;
        }

        $testUser = $this->option('test-user');
        $dryRun = (bool) $this->option('dry-run');
        $today = Carbon::now()->startOfDay();

        $query = User::query()
            ->with('organizations')
            ->whereHas('telegramUser')
            ->whereHas('organizations');

        if ($testUser) {
            $query->where('id', (int) $testUser);
        }

        $generated = 0;
        $pairs = 0;

        $query->chunkById(200, function ($users) use (&$generated, &$pairs, $dryRun, $service, $today) {
            foreach ($users as $user) {
                foreach ($user->organizations as $org) {
                    $pairs++;
                    if ($dryRun) {
                        $this->line(sprintf('DRY: user=%d (%s) org=%d (%s)', $user->id, $user->name, $org->id, $org->name));
                        continue;
                    }

                    $advice = $service->generate($user, $org, $today);
                    if ($advice !== null && ! empty($advice)) {
                        $generated++;
                    }
                }
            }
        });

        $this->info(sprintf(
            'Meetings advice: evaluated %d (user, org) pairs, generated %d%s.',
            $pairs,
            $generated,
            $dryRun ? ' (dry-run, none persisted)' : ''
        ));

        return self::SUCCESS;
    }
}
