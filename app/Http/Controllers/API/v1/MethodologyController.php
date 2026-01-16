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
     * @queryParam offset integer The number of items to skip. Example: 0
     * @queryParam limit integer The number of items to return. Example: 25
     *
     * @response 200 scenario="OK" {"success":true,"data":[{"id":1,"name":"Scrum","text":"..."}],"meta":{"count":1}}
     * @response 403 scenario="Forbidden" {"success":false,"message":"This action is unauthorized."}
     */
    public function index(MethodologyRequest $request, Organization $organization): ApiResponse
    {
        Gate::authorize('viewAny', [Methodology::class, $organization]);

        $methodologies = $organization->methodologies();

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
     * @urlParam organization integer required The organization ID. Example: 10
     * @bodyParam name string required The methodology name. Example: Scrum
     * @bodyParam text string required The methodology description text. Example: "A lightweight agile framework..."
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

            DB::commit();

            return ApiResponse::success(
                data: MethodologyResource::make($methodology)
            );
        } catch (\Exception $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /**
     * Create a methodology
     *
     * @group Methodologies
     *
     * Creates a new methodology under the given organization and dispatches a background job
     * to generate its scheme.
     *
     * @urlParam organization integer required The organization ID. Example: 10
     * @bodyParam name string required The methodology name. Example: Scrum
     * @bodyParam text string required The methodology description text. Example: "A lightweight agile framework..."
     *
     * @response 200 scenario="Created" {"success":true,"data":{"id":1,"name":"Scrum","text":"..."}}
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
     * @bodyParam name string required The methodology name. Example: Kanban
     * @bodyParam text string required The methodology description text. Example: "Visual workflow management..."
     *
     * @response 200 scenario="OK" {"success":true,"data":{"id":1,"name":"Kanban","text":"..."}}
     * @response 403 scenario="Forbidden" {"success":false,"message":"This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message":"No query results for model [Methodology] 999"}
     * @response 409 scenario="Locked (used by follow-ups)" {"success":false,"message":"The methodology is used by one or more follow-ups.","code":"METHODOLOGY_LOCK_UPDATE"}
     * @response 409 scenario="Locked (default)" {"success":false,"message":"Unable to update default methodology.","code":"METHODOLOGY_LOCK_DEFAULT"}
     */
    public function update(MethodologyRequest $request, Methodology $methodology): ApiResponse
    {
        Gate::authorize('create', [$methodology, $methodology->team]);

        if ($methodology->followups()->exists()) {
            throw new AppException('The methodology is used by one or more follow-ups.', 'METHODOLOGY_LOCK_UPDATE');
        }

        if ($methodology->isDefault()) {
            throw new AppException('Unable to update default methodology.', 'METHODOLOGY_LOCK_DEFAULT');
        }

        $methodology->update($request->getUpdateData());

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
        Gate::authorize('delete', [$methodology, $methodology->team]);

        if ($methodology->followups()->exists()) {
            throw new AppException('The methodology is used by one or more follow-ups.', 'METHODOLOGY_LOCK_UPDATE');
        }

        if ($methodology->isDefault()) {
            throw new AppException('Unable to delete default methodology.', 'METHODOLOGY_LOCK_DEFAULT');
        }

        $methodology->delete();

        return ApiResponse::success();
    }
}
