<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Models\User;
use App\Services\Today\DailyNudgeService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateDailyNudgesCommand extends Command
{
    protected $signature = 'today:generate-nudges';
    protected $description = 'Generate daily AI nudges for all active users with connected calendars';

    public function handle(DailyNudgeService $service): int
    {
        $userIds = Source::query()->distinct()->pluck('user_id');
        $users = User::whereIn('id', $userIds)->get();
        $today = Carbon::today(config('app.timezone'));

        $this->info("Generating nudges for {$users->count()} users...");

        $generated = 0;

        foreach ($users as $user) {
            $nudge = $service->generate($user, $today);

            if ($nudge) {
                $generated++;
                $this->line("  [{$user->id}] {$user->name}: {$nudge}");
            } else {
                $this->line("  [{$user->id}] {$user->name}: skipped (no data or error)");
            }
        }

        $this->info("Done. Generated {$generated}/{$users->count()} nudges.");

        return self::SUCCESS;
    }
}
