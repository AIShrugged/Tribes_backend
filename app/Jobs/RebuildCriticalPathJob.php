<?php

namespace App\Jobs;

use App\Models\CriticalPathGraph;
use App\Services\CriticalPath\CriticalPathService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RebuildCriticalPathJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(
        public readonly ?int $teamId,
        public readonly ?int $organizationId,
    ) {}

    public function uniqueId(): string
    {
        return 'cpm_rebuild_team_'.($this->teamId ?? 'null')
            .'_org_'.($this->organizationId ?? 'null');
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(CriticalPathService $service): void
    {
        // Mark as computing immediately so frontend can start polling
        CriticalPathGraph::updateOrCreate(
            ['team_id' => $this->teamId, 'organization_id' => $this->organizationId],
            ['status' => 'computing', 'computed_at' => null],
        );

        $graph = $service->rebuild($this->teamId, $this->organizationId);

        if (! $graph->isReady()) {
            return;
        }

        // Isolate the Telegram send in a tries=1 job so a compute retry here never re-sends.
        NotifyCriticalPathJob::dispatch($graph->id);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('RebuildCriticalPathJob failed', [
            'team_id' => $this->teamId,
            'org_id' => $this->organizationId,
            'error' => $e->getMessage(),
        ]);

        CriticalPathGraph::where('team_id', $this->teamId)
            ->where('organization_id', $this->organizationId)
            ->update(['status' => 'failed']);
    }
}
