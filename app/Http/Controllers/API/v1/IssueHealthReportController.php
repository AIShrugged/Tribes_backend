<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Jobs\GenerateIssueHealthReportJob;
use App\Models\IssueHealthReport;
use App\Models\Team;
use App\Services\Issue\IssueHealthDetector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class IssueHealthReportController extends Controller
{
    public function __construct(
        private readonly IssueHealthDetector $detector,
    ) {}

    public function show(Request $request, Team $team): ApiResponse
    {
        Gate::authorize('view', [IssueHealthReport::class, $team]);

        $report = IssueHealthReport::where('team_id', $team->id)
            ->orderByDesc('period_start')
            ->first();

        if (! $report) {
            return ApiResponse::success('No report yet', null);
        }

        return ApiResponse::success('OK', $this->formatReport($report, $team));
    }

    public function refresh(Request $request, Team $team): ApiResponse
    {
        Gate::authorize('view', [IssueHealthReport::class, $team]);

        // Preserve the latest findings so the UI keeps showing old data while the job runs
        $latest = IssueHealthReport::where('team_id', $team->id)
            ->orderByDesc('period_start')
            ->first();

        $report = IssueHealthReport::updateOrCreate(
            [
                'team_id'         => $team->id,
                'organization_id' => $team->organization_id,
                'period_start'    => today()->toDateString(),
            ],
            [
                'status'       => 'pending',
                'generated_at' => now(),
                'expires_at'   => now()->addDays(30),
                'findings'     => $latest?->findings ?? $this->emptyFindings(),
                'ai_summary'   => $latest?->ai_summary,
            ]
        );

        GenerateIssueHealthReportJob::dispatch($team->id);

        return ApiResponse::success('Analysis queued', $this->formatReport($report, $team));
    }

    private function formatReport(IssueHealthReport $report, Team $team): array
    {
        return [
            'id'           => $report->id,
            'team_id'      => $report->team_id,
            'period_start' => $report->period_start->toDateString(),
            'generated_at' => $report->generated_at->toIso8601String(),
            'status'       => $report->status ?? 'done',
            'findings'     => $report->findings,
            'ai_summary'   => $report->ai_summary,
            'live_counts'  => $this->detector->computeLiveCounts($team),
        ];
    }

    private function emptyFindings(): array
    {
        return [
            'schema_version' => 1,
            'counts'         => ['total_analyzed' => 0, 'overdue' => 0, 'sprint_risk' => 0, 'priority_ignored' => 0],
            'overdue'        => [],
            'sprint_risk'    => [],
            'priority_ignored' => [],
        ];
    }
}
