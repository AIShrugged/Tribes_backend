<?php

namespace App\Jobs;

use App\Models\CriticalPathGraph;
use App\Services\CriticalPath\CriticalPathNotificationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Isolates the critical-path team Telegram notification on its own queue with tries=1.
 *
 * Why a dedicated job: the dominant trigger (CriticalPathService::incrementalRebuild) runs
 * inline in the scheduler, and RebuildCriticalPathJob retries its (LLM) compute. Sending the
 * Telegram message there would either ride the scheduler tick unprotected or get re-sent on a
 * compute retry. Extracting the send into a tries=1 job satisfies the "TG senders => tries=1"
 * invariant and guarantees at most one send per ready transition (dedup/debounce lives in
 * CriticalPathNotificationService::notifyTeam).
 */
class NotifyCriticalPathJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $graphId) {}

    public function uniqueId(): string
    {
        return 'notify_cpm_graph_'.$this->graphId;
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    public function handle(CriticalPathNotificationService $notifier): void
    {
        $graph = CriticalPathGraph::with(['nodes.issue', 'edges'])->find($this->graphId);

        if (! $graph || ! $graph->isReady()) {
            return;
        }

        $notifier->notifyTeam($graph);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('NotifyCriticalPathJob failed', [
            'graph_id' => $this->graphId,
            'error' => $e->getMessage(),
        ]);
    }
}
