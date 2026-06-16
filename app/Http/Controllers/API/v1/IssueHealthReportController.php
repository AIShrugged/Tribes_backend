<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\IssueHealthReport;
use App\Models\Team;
use App\Services\Issue\IssueHealthDetector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class IssueHealthReportController extends Controller
{
    public function __construct(private readonly IssueHealthDetector $detector) {}

    public function show(Request $request, Team $team): ApiResponse
    {
        Gate::authorize('view', [IssueHealthReport::class, $team]);

        $report = IssueHealthReport::where('team_id', $team->id)
            ->orderByDesc('period_start')
            ->first();

        if (! $report) {
            return ApiResponse::success('No report yet', null);
        }

        return ApiResponse::success('OK', [
            'id'           => $report->id,
            'team_id'      => $report->team_id,
            'period_start' => $report->period_start->toDateString(),
            'generated_at' => $report->generated_at->toIso8601String(),
            'findings'     => $report->findings,
            'ai_summary'   => $report->ai_summary,
            'live_counts'  => $this->detector->computeLiveCounts($team),
        ]);
    }
}
