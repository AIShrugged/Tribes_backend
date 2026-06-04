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
     * List team members
     *
     * @group TeamUsers
     *
     * Returns a paginated list of members in the given team.
     * The total count is returned in the `Items-Count` response header.
     *
     * @urlParam team integer required The Team ID. Example: 2
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "user": {"id": 1, "name": "Alice Johnson", "email": "alice@example.com"},
     *       "teams": {"id": 2, "name": "Core Team", "slug": "core-team"}
     *     }
     *   ],
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
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
     * Get team member
     *
     * @group TeamUsers
     *
     * Returns a single team member record.
     *
     * @urlParam team integer required The Team ID. Example: 2
     * @urlParam user integer required The TeamUser ID. Example: 1
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "user": {"id": 1, "name": "Alice Johnson", "email": "alice@example.com"},
     *     "teams": {"id": 2, "name": "Core Team", "slug": "core-team"}
     *   },
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [TeamUser] 1"}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function show(TeamUserRequest $request, Team $team, TeamUser $user): ApiResponse
    {
        Gate::authorize('view', [$user, $team]);

        return ApiResponse::success(data: TeamUserResource::make($user));
    }

    /**
     * Kick member from team
     *
     * @group TeamUsers
     *
     * Removes a user from the team.
     *
     * @urlParam team integer required The Team ID. Example: 2
     * @urlParam user integer required The TeamUser ID. Example: 1
     *
     * @response 200 scenario="OK" {"success": true, "data": null, "message": "Success", "status": 200, "meta": {}}
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [TeamUser] 1"}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function kick(TeamUserRequest $request, Team $team, TeamUser $user): ApiResponse
    {
        Gate::authorize('kick', [$user, $team]);

        $user->delete();

        return ApiResponse::success();
    }
}
