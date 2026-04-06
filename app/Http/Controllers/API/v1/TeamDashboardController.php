<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\TeamDashboardRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Team;
use App\Services\Dashboard\TeamDashboardService;
use Illuminate\Support\Facades\Gate;

class TeamDashboardController extends Controller
{
    /**
     * Get team dashboard
     *
     * Returns a team-scoped dashboard payload.
     *
     * @group Teams
     * @authenticated
     *
     * @urlParam team integer required The team ID. Example: 5
     */
    public function show(
        TeamDashboardRequest $request,
        Team $team,
        TeamDashboardService $service,
    ): ApiResponse {
        Gate::authorize('view', $team);

        return ApiResponse::success(
            data: $service->build($team, $request->user())
        );
    }
}
