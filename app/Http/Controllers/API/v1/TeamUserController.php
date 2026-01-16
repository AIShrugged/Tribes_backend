<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\TeamUserRequest;
use App\Http\Resources\API\v1\TeamUserResource;
use App\Http\Responses\ApiResponse;
use App\Models\Team;
use App\Models\TeamUser;
use Illuminate\Support\Facades\Gate;

class TeamUserController extends Controller
{
    /**
     * @param TeamUserRequest $request
     * @param Team $team
     * @return ApiResponse
     *
     * @group TeamUsers
     *
     * Get a list of team members
     *
     */
    public function index(TeamUserRequest $request, Team $team): ApiResponse
    {
        Gate::authorize('viewAny', [TeamUser::class, $team]);

        $teamUsers = $team->teamUsers();

        $count = $teamUsers->count();

        $teamUsers = $teamUsers->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(TeamUserResource::collection($teamUsers), $count);
    }

    /**
     * @param TeamUserRequest $request
     * @param Team $team
     * @param TeamUser $user
     * @return ApiResponse
     *
     * @group TeamUsers
     *
     * Get specific team member
     *
     */
    public function show(TeamUserRequest $request, Team $team, TeamUser $user): ApiResponse
    {
        Gate::authorize('view', [$user, $team]);

        return ApiResponse::success(data: TeamUserResource::make($user));
    }

    /**
     * @param TeamUserRequest $request
     * @param Team $team
     * @param TeamUser $user
     * @return ApiResponse
     *
     * @group TeamUsers
     *
     * Kick member from the team
     */
    public function kick(TeamUserRequest $request, Team $team, TeamUser $user): ApiResponse
    {
        Gate::authorize('kick', [$user, $team]);

        $team->teamUsers()->delete($user);

        return ApiResponse::success();
    }
}
