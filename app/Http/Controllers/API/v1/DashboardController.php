<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Dashboard\DashboardService;
use Illuminate\Support\Facades\Auth;

/**
 * @group Dashboard
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    /**
     * Get dashboard statistics
     *
     * Returns aggregated statistics for the authenticated user's dashboard,
     * including meetings, participants, tasks, follow-ups, summaries and teams.
     *
     * @authenticated
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": {
     *     "meetings": {
     *       "total": 42,
     *       "with_bot": 18,
     *       "total_duration_minutes": 1260,
     *       "average_duration_minutes": 30,
     *       "recent": [
     *         {
     *           "id": 10,
     *           "title": "Q1 Planning",
     *           "starts_at": "2026-03-01T10:00:00.000000Z",
     *           "ends_at": "2026-03-01T11:00:00.000000Z",
     *           "duration_minutes": 60,
     *           "participants_count": 5
     *         }
     *       ],
     *       "by_month": [
     *         {"month": "2026-02", "count": 12, "total_duration_minutes": 360}
     *       ]
     *     },
     *     "participants": {
     *       "total_unique": 24,
     *       "average_per_meeting": 3.5,
     *       "top": [{"name": "John Doe", "meetings_count": 8}]
     *     },
     *     "tasks": {
     *       "total": 30,
     *       "by_status": {"open": 10, "in_progress": 5, "done": 14, "cancelled": 1},
     *       "overdue": 3
     *     },
     *     "followups": {
     *       "total": 20,
     *       "by_status": {"done": 14, "in_progress": 4, "failed": 2}
     *     },
     *     "summaries": {"total": 15},
     *     "teams": {
     *       "total": 3,
     *       "list": [{"id": 1, "name": "Core Team", "members_count": 8}]
     *     }
     *   },
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function index(): ApiResponse
    {
        $stats = $this->dashboardService->getStats(Auth::user());

        return ApiResponse::success(data: $stats);
    }
}
