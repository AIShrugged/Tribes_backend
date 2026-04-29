<?php

namespace App\Jobs;

use App\Models\CriticalPathGraph;
use App\Models\CriticalPathNode;
use App\Services\CriticalPath\CriticalPathComputer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Structural CPM update — no LLM.
 * Removes or restores a node and reruns the CPM algorithm.
 * Triggered immediately on status→done, deleted, blocker change.
 */
class QuickCpmUpdateJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(
        public readonly int $issueId,
        public readonly ?int $organizationId,
        public readonly bool $removeFromGraph,
    ) {}

    public function uniqueId(): string
    {
        return 'quick_cpm_issue_'.$this->issueId;
    }

    public function handle(CriticalPathComputer $computer): void
    {
        $graph = CriticalPathGraph::where('organization_id', $this->organizationId)
            ->whereNull('team_id')
            ->where('status', 'ready')
            ->first();

        if (! $graph) {
            return;
        }

        $node = CriticalPathNode::where('graph_id', $graph->id)
            ->where('issue_id', $this->issueId)
            ->first();

        if ($this->removeFromGraph) {
            // Node goes away — delete it (cascade removes its edges too)
            $node?->delete();
        } else {
            // Issue came back (reopen) but no node exists yet — will be handled by next incremental
            if ($node) {
                return;
            }
        }

        try {
            $computer->compute($graph->fresh());
        } catch (\Throwable $e) {
            Log::warning('QuickCpmUpdateJob: CPM recompute failed', [
                'graph_id' => $graph->id,
                'issue_id' => $this->issueId,
                'error' => $e->getMessage(),
            ]);
            $graph->update(['status' => 'failed']);
        }
    }
}
