<?php

namespace App\Jobs;

use App\Models\Team;
use App\Services\Issue\IssueHealthAnalysisService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;

class GenerateTeamHealthReportJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public int $uniqueFor = 3600;

    public function __construct(public int $teamId) {}

    public function uniqueId(): string
    {
        return (string) $this->teamId;
    }

    public function middleware(): array
    {
        return [new RateLimited('openrouter-digests')];
    }

    public function handle(IssueHealthAnalysisService $service): void
    {
        $team = Team::with('organization')->find($this->teamId);

        if (! $team) {
            return;
        }

        $report = $service->generate($team);

        Log::info('GenerateTeamHealthReportJob: done', [
            'team_id'    => $this->teamId,
            'has_report' => $report !== null,
            'counts'     => $report?->findings['counts'] ?? null,
        ]);
    }
}
