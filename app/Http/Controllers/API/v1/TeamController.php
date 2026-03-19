<?php

namespace App\Http\Controllers\API\v1;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\TeamRequest;
use App\Http\Resources\API\v1\MethodologyResource;
use App\Http\Resources\API\v1\TeamResource;
use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Models\Team;
use App\Services\Workspace\WorkspaceBootstrapService;
use Illuminate\Support\Facades\Gate;

class TeamController extends Controller
{
    public function __construct(
        private readonly WorkspaceBootstrapService $workspaceBootstrapService,
    ) {}

    /**
     * List teams
     *
     * @group Teams
     *
     * Returns a paginated list of teams for the given organization.
     *
     *
     * @response 200 scenario="OK" {"success":true,"data":[{"id":1,"name":"Core Team","slug":"core-team","employee_count":12}]}
     * @response 401 scenario="Unauthenticated" {"message":"Unauthenticated."}
     * @response 403 scenario="Forbidden" {"success":false,"message":"This action is unauthorized."}
     */
    public function index(TeamRequest $request, Organization $organization): ApiResponse
    {
        Gate::authorize('viewAny', [Team::class, $organization]);

        $teams = $organization->teams()->visibleFor($request->user(), $organization);

        $count = $teams->count();

        $teams = $teams->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(TeamResource::collection($teams), $count);
    }

    /**
     * Create a team
     *
     * @group Teams
     *
     * Creates a new team in the given organization. A default methodology is assigned automatically.
     *
     * @urlParam organization integer required The organization ID. Example: 10
     *
     * @response 200 scenario="Created" {"success":true,"data":{"id":1,"name":"Core Team","slug":"core-team","employee_count":0}}
     * @response 401 scenario="Unauthenticated" {"message":"Unauthenticated."}
     * @response 403 scenario="Forbidden" {"success":false,"message":"This action is unauthorized."}
     */
    public function store(TeamRequest $request): ApiResponse
    {
        Gate::authorize('create', [Team::class, $request->getOrganizationId()]);

        $organization = Organization::findOrFail($request->getOrganizationId());

        $team = $organization->teams()->create($request->getStoreData());
        $this->workspaceBootstrapService->ensureTeamDefaults($team);

        return ApiResponse::success(data: TeamResource::make($team));
    }

    /**
     * Get a team
     *
     * @group Teams
     *
     * Returns a single team by ID.
     *
     * @urlParam team integer required The team ID. Example: 5
     *
     * @response 200 scenario="OK" {"success":true,"data":{"id":5,"name":"Core Team","slug":"core-team","employee_count":12,"members":[{"id":1,"name":"John Doe","email":"john@example.com"}]}}
     * @response 401 scenario="Unauthenticated" {"message":"Unauthenticated."}
     * @response 403 scenario="Forbidden" {"success":false,"message":"This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message":"No query results for model [Team] 999"}
     */
    public function show(TeamRequest $request, Team $team): ApiResponse
    {
        Gate::authorize('view', $team);

        $team->load('users');

        return ApiResponse::success(data: TeamResource::make($team));
    }

    /**
     * Update a team
     *
     * @group Teams
     *
     * Updates team fields. The request payload includes the team ID and optional fields to change.
     *
     * @urlParam team integer required The team ID (route model binding). Example: 5
     *
     * @response 200 scenario="OK" {"success":true,"data":{"id":5,"name":"Platform Team","slug":"platform-team","employee_count":12}}
     * @response 401 scenario="Unauthenticated" {"message":"Unauthenticated."}
     * @response 403 scenario="Forbidden" {"success":false,"message":"This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message":"No query results for model [Team] 999"}
     */
    public function update(TeamRequest $request, Team $team): ApiResponse
    {
        Gate::authorize('update', $team);

        if (Team::firstOrFail('slug', $request->getSlug())) {
            throw new AppException('Team slug should be unique.', 'TEAM_ALREADY_EXIST', 400);
        }

        $team->update($request->getUpdateData());

        return ApiResponse::success(data: TeamResource::make($team->refresh()));
    }

    /**
     * Get active team methodology
     *
     * @group Teams
     *
     * Returns the currently assigned methodology for the team.
     *
     * @urlParam team integer required The team ID. Example: 5
     *
     * @response 200 scenario="OK" {"success":true,"data":{"id":1,"name":"Scrum","text":"..."}}
     * @response 401 scenario="Unauthenticated" {"message":"Unauthenticated."}
     * @response 403 scenario="Forbidden" {"success":false,"message":"This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message":"No query results for model [Team] 999"}
     */
    public function activeMethodology(TeamRequest $request, Team $team): ApiResponse
    {
        Gate::authorize('view', $team);

        return ApiResponse::success(data: MethodologyResource::make($team->methodology));
    }

    /**
     * Assign methodology to a team
     *
     * @group Teams
     *
     * Assigns a methodology to the given team and returns the updated team.
     *
     * @urlParam team integer required The team ID. Example: 5
     *
     * @response 200 scenario="OK" {"success":true,"data":{"id":5,"name":"Core Team","slug":"core-team","employee_count":12}}
     * @response 401 scenario="Unauthenticated" {"message":"Unauthenticated."}
     * @response 403 scenario="Forbidden" {"success":false,"message":"This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message":"No query results for model [Team] 999"}
     */
    public function assignMethodologyForTeam(TeamRequest $request): ApiResponse
    {
        $team = Team::findOrFail($request->getTeamId());

        Gate::authorize('create', [Team::class, $team->organization]);

        $team->assignMethodology($request->getMethodologyId());

        return ApiResponse::success(data: TeamResource::make($team->refresh()));
    }

    /**
     * Delete a team
     *
     * @group Teams
     *
     * Permanently deletes the team along with all its members.
     *
     * @urlParam team integer required The team ID. Example: 5
     *
     * @response 200 scenario="OK" {"success": true, "data": null, "message": "Success", "status": 200, "meta": {}}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     * @response 403 scenario="Forbidden" {"success": false, "message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [Team] 999"}
     */
    public function destroy(TeamRequest $request, Team $team): ApiResponse
    {
        Gate::authorize('destroy', $team);

        $team->deleteCompletely();

        return ApiResponse::success();
    }
}
