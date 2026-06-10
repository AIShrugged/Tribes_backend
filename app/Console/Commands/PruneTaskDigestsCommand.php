<?php

namespace App\Console\Commands;

use App\Models\IssueHealthReport;
use App\Models\TaskDigest;
use Illuminate\Console\Command;

class PruneTaskDigestsCommand extends Command
{
    protected $signature = 'digests:prune';

    protected $description = 'Delete task_digests rows past their expires_at timestamp.';

    public function handle(): int
    {
        $deleted = TaskDigest::query()->where('expires_at', '<', now())->delete();
        $this->info("Pruned {$deleted} expired task_digests row(s).");

        $deletedReports = IssueHealthReport::query()->where('expires_at', '<', now())->delete();
        $this->info("Pruned {$deletedReports} expired issue_health_reports row(s).");

        return self::SUCCESS;
    }
}
