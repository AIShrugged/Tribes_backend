<?php

namespace App\Jobs;

use App\Models\IssueHealthReport;
use App\Models\Team;
use App\Services\Issue\IssueHealthAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateIssueHealthReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $teamId) {}

    public function handle(IssueHealthAnalysisService $service): void
    {
        $team = Team::with('organization')->findOrFail($this->teamId);

        $report = $service->generate($team);

        if (! $report) {
            // No problems detected — revert pending record to done with existing findings
            IssueHealthReport::where('team_id', $this->teamId)
                ->where('status', 'pending')
                ->update(['status' => 'done']);
        }
    }

    public function failed(\Throwable $e): void
    {
        IssueHealthReport::where('team_id', $this->teamId)
            ->where('status', 'pending')
            ->update(['status' => 'failed']);

        Log::error('GenerateIssueHealthReportJob failed', [
            'team_id' => $this->teamId,
            'error'   => $e->getMessage(),
        ]);
    }
}
