<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\IssueStatsHistoryRequest;
use App\Http\Responses\ApiResponse;
use App\Services\IssueStatsService;
use Illuminate\Support\Facades\Auth;

class IssueStatsController extends Controller
{
    public function __construct(
        private readonly IssueStatsService $issueStatsService,
    ) {}

    /**
     * Get aggregated issue/task statistics for the authenticated user.
     *
     * Returns counts by status (total, open, in_progress, paused, completed, overdue)
     * scoped to all issues visible to the user (org/team/personal), plus
     * deltas comparing today's updated_at counts to yesterday's, and
     * closed-task summary fields (closed_today, closed_this_week, etc.).
     *
     * @authenticated
     */
    public function index(): ApiResponse
    {
        $stats = $this->issueStatsService->getStats(Auth::user());

        return ApiResponse::success(data: $stats);
    }

    /**
     * Get time-series history of closed tasks, grouped by period.
     *
     * @authenticated
     */
    public function history(IssueStatsHistoryRequest $request): ApiResponse
    {
        $dto = $this->issueStatsService->getHistory(
            Auth::user(),
            $request->getPeriod(),
            $request->getRange(),
        );

        return ApiResponse::success(data: $dto);
    }
}