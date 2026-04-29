<?php

namespace App\Console\Commands;

use App\Jobs\RebuildCriticalPathJob;
use App\Models\CriticalPathGraph;
use App\Models\CriticalPathPendingIssue;
use App\Services\CriticalPath\CriticalPathService;
use Illuminate\Console\Command;

class ProcessCriticalPathPendingCommand extends Command
{
    protected $signature = 'cpm:process-pending';

    protected $description = 'Flush the CPM pending buffer and run incremental or full rebuilds per organization';

    public function handle(CriticalPathService $service): int
    {
        $orgIds = CriticalPathPendingIssue::query()
            ->distinct()
            ->pluck('organization_id');

        foreach ($orgIds as $orgId) {
            $graph = CriticalPathGraph::where('organization_id', $orgId)
                ->whereNull('team_id')
                ->first();

            if ($graph && $graph->status === 'computing') {
                // Full rebuild already running — it will incorporate latest state
                continue;
            }

            $pendingIssueIds = CriticalPathPendingIssue::where('organization_id', $orgId)
                ->pluck('issue_id')
                ->all();

            CriticalPathPendingIssue::where('organization_id', $orgId)->delete();

            if (! $graph || $graph->status === 'failed') {
                RebuildCriticalPathJob::dispatch(null, $orgId);
            } else {
                $service->incrementalRebuild($orgId, $pendingIssueIds);
            }
        }

        return Command::SUCCESS;
    }
}
