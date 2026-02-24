<?php

namespace App\Http\Controllers\API\v1;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\MethodologyRequest;
use App\Http\Resources\API\v1\MethodologyResource;
use App\Http\Responses\ApiResponse;
use App\Jobs\GenerateMethodologySchemeJob;
use App\Models\Methodology;
use App\Models\Organization;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class MethodologyController extends Controller
{
    /**
     * List methodologies
     *
     * @group Methodologies
     *
     * Returns a paginated list of methodologies for the given organization.
     *
     * @urlParam organization integer required The organization ID. Example: 10
     *
     * @response 200 scenario="OK" {"success":true,"data":[{"id":1,"name":"Scrum","text":"..."}],"meta":{"count":1}}
     * @response 403 scenario="Forbidden" {"success":false,"message":"This action is unauthorized."}
     */
    public function index(MethodologyRequest $request, Organization $organization): ApiResponse
    {
        Gate::authorize('viewAny', [Methodology::class, $organization]);

        $methodologies = Methodology::visibleFor($request->user(), $organization);

        $count = $methodologies->count();

        $methodologies = $methodologies->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(MethodologyResource::collection($methodologies), $count);
    }

    /**
     * Create a methodology
     *
     * @group Methodologies
     *
     * Creates a new methodology under the given organization and dispatches a background job
     * to generate its scheme.
     *
     *
     * @response 200 scenario="Created" {"success":true,"data":{"id":1,"name":"Scrum","text":"..."}}
     * @response 403 scenario="Forbidden" {"success":false,"message":"This action is unauthorized."}
     */
    public function store(MethodologyRequest $request): ApiResponse
    {
        Gate::authorize('create', [Methodology::class, $request->getOrganizationId()]);

        try {
            DB::beginTransaction();

            $organization = Organization::findOrFail($request->getOrganizationId());

            $methodology = $organization->methodologies()->create($request->getStoreData());

            GenerateMethodologySchemeJob::dispatch($methodology);

            if ($request->getTeamIds() !== null) {
                $methodology->syncTeams($request->getTeamIds());
            }

            DB::commit();

            return ApiResponse::success(
                data: MethodologyResource::make($methodology->refresh())
            );
        } catch (\Exception $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /**
     * Get specific methodology
     *
     * @group Methodologies
     *
     * @response 200 scenario="OK" {"success":true,"data":{"id":1,"name":"Scrum","text":"..."}}
     * @response 403 scenario="Forbidden" {"success":false,"message":"This action is unauthorized."}
     */
    public function show(MethodologyRequest $request, Methodology $methodology): ApiResponse
    {
        Gate::authorize('view', $methodology);

        return ApiResponse::success(
            data: MethodologyResource::make($methodology)
        );
    }

    /**
     * Update a methodology
     *
     * @group Methodologies
     *
     * Updates the given methodology.
     * This endpoint is blocked if the methodology is default or is used by follow-ups.
     *
     * @urlParam methodology integer required The methodology ID. Example: 1
     *
     * @response 200 scenario="OK" {"success":true,"data":{"id":1,"name":"Kanban","text":"..."}}
     * @response 403 scenario="Forbidden" {"success":false,"message":"This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message":"No query results for model [Methodology] 999"}
     * @response 409 scenario="Locked (used by follow-ups)" {"success":false,"message":"The methodology is used by one or more follow-ups.","code":"METHODOLOGY_LOCK_UPDATE"}
     * @response 409 scenario="Locked (default)" {"success":false,"message":"Unable to update default methodology.","code":"METHODOLOGY_LOCK_DEFAULT"}
     */
    public function update(MethodologyRequest $request, Methodology $methodology): ApiResponse
    {
        Gate::authorize('create', $methodology->organization);

        if ($methodology->isDefault()) {
            throw new AppException('Unable to update default methodology.', 'METHODOLOGY_LOCK_DEFAULT');
        }

        $updateData = $request->getUpdateData();

        // Lock name/text changes if methodology is actively used
        if (!empty($updateData) && ($methodology->followups()->exists() || $methodology->teams()->exists())) {
            throw new AppException('The methodology is in use by teams or followups.', 'METHODOLOGY_LOCK_UPDATE');
        }

        if (!empty($updateData)) {
            $methodology->update($updateData);
        }

        if ($request->getTeamIds() !== null) {
            $methodology->syncTeams($request->getTeamIds());
        }

        return ApiResponse::success(
            data: MethodologyResource::make($methodology->refresh())
        );
    }

    /**
     * Delete a methodology
     *
     * @group Methodologies
     *
     * Deletes the given methodology.
     * This endpoint is blocked if the methodology is default or is used by follow-ups.
     *
     * @urlParam methodology integer required The methodology ID. Example: 1
     *
     * @response 200 scenario="OK" {"success":true}
     * @response 403 scenario="Forbidden" {"success":false,"message":"This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message":"No query results for model [Methodology] 999"}
     * @response 409 scenario="Locked (used by follow-ups)" {"success":false,"message":"The methodology is used by one or more follow-ups.","code":"METHODOLOGY_LOCK_UPDATE"}
     * @response 409 scenario="Locked (default)" {"success":false,"message":"Unable to delete default methodology.","code":"METHODOLOGY_LOCK_DEFAULT"}
     */
    public function destroy(MethodologyRequest $request, Methodology $methodology): ApiResponse
    {
        Gate::authorize('delete', $methodology);

        if ($methodology->followups()->exists() || $methodology->teams()->exists()) {
            throw new AppException('The methodology is in use by teams or followups.', 'METHODOLOGY_LOCK_UPDATE');
        }

        if ($methodology->isDefault()) {
            throw new AppException('Unable to delete default methodology.', 'METHODOLOGY_LOCK_DEFAULT');
        }

        $methodology->delete();

        return ApiResponse::success();
    }

    //TODO: fix bug with bot deletion mid call, fix bug with bot change for the same event, when time change to future from before
}
