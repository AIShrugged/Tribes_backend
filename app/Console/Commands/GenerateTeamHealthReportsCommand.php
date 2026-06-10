<?php

namespace App\Console\Commands;

use App\Jobs\GenerateTeamHealthReportJob;
use App\Models\Team;
use Illuminate\Console\Command;

class GenerateTeamHealthReportsCommand extends Command
{
    protected $signature = 'issues:generate-health-reports
        {--test-team= : Only dispatch for this team ID}
        {--dry-run : Log targets without queueing}';

    protected $description = 'Dispatch daily issue health analysis per team.';

    public function handle(): int
    {
        $testTeamId = $this->option('test-team');
        $dryRun = $this->option('dry-run');

        $query = Team::query();

        if ($testTeamId) {
            $query->where('id', (int) $testTeamId);
        }

        $dispatched = 0;

        $query->chunkById(200, function ($teams) use ($dryRun, &$dispatched) {
            foreach ($teams as $team) {
                if ($dryRun) {
                    $this->info("Would dispatch: team #{$team->id} ({$team->name})");
                    continue;
                }
                GenerateTeamHealthReportJob::dispatch($team->id);
                $dispatched++;
            }
        });

        $this->info("Dispatched {$dispatched} health report jobs.");

        return self::SUCCESS;
    }
}
