<?php

namespace App\Http\Controllers\API\v1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\OrganizationRequest;
use App\Http\Resources\API\v1\OrganizationResource;
use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrganizationController extends Controller
{
    use AuthorizesRequests;

    public function __construct()
    {
        $this->authorizeResource(Organization::class, 'organization');
    }

    /**
     * List organizations
     *
     * @group Organizations
     *
     * Returns a paginated list of organizations that belong to the authenticated user.
     *
     *
     * @response 200 scenario="OK" {"success":true,"data":[{"id":1,"name":"Acme Inc"}],"meta":{"count":1}}
     * @response 401 scenario="Unauthenticated" {"message":"Unauthenticated."}
     */
    public function index(OrganizationRequest $request): ApiResponse
    {
        $organizations = Auth::user()->organizations();

        $count = $organizations->count();

        $organizations = $organizations->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(OrganizationResource::collection($organizations), $count);
    }

    /**
     * Get an organization
     *
     * @group Organizations
     *
     * Returns a single organization by ID.
     *
     * @urlParam organization integer required The organization ID. Example: 10
     *
     * @response 200 scenario="OK" {"success":true,"data":{"id":10,"name":"Acme Inc"}}
     * @response 401 scenario="Unauthenticated" {"message":"Unauthenticated."}
     * @response 403 scenario="Forbidden" {"success":false,"message":"This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message":"No query results for model [Organization] 999"}
     */
    public function show(OrganizationRequest $request, Organization $organization): ApiResponse
    {
        return ApiResponse::success(
            data: OrganizationResource::make($organization)
        );
    }

    /**
     * Create an organization
     *
     * @group Organizations
     *
     * Creates a new organization and assigns the authenticated user a manager role in it.
     *
     *
     * @response 200 scenario="Created" {"success":true,"data":{"id":10,"name":"Acme Inc"}}
     * @response 401 scenario="Unauthenticated" {"message":"Unauthenticated."}
     * @response 403 scenario="Forbidden" {"success":false,"message":"This action is unauthorized."}
     */

    public function store(OrganizationRequest $request): ApiResponse
    {
        try {
            DB::beginTransaction();

            $organization = Organization::create($request->getStoreData());

            $organization->users()->attach(Auth::id(), ['role' => UserRole::MANAGER->value]);

            DB::commit();
            return ApiResponse::success(data: OrganizationResource::make($organization));
        } catch (\Exception $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /**
     * Update an organization
     *
     * @group Organizations
     *
     * Updates the given organization.
     *
     * @urlParam organization integer required The organization ID. Example: 10
     *
     * @response 200 scenario="OK" {"success":true,"data":{"id":10,"name":"Acme Inc"}}
     * @response 401 scenario="Unauthenticated" {"message":"Unauthenticated."}
     * @response 403 scenario="Forbidden" {"success":false,"message":"This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message":"No query results for model [Organization] 999"}
     */

    public function update(OrganizationRequest $request, Organization $organization): ApiResponse
    {
        $organization->update($request->getUpdateData());

        return ApiResponse::success(data: OrganizationResource::make($organization->refresh()));
    }

    /**
     * Delete an organization
     *
     * @group Organizations
     *
     * Permanently deletes the organization along with all its teams and members.
     *
     * @urlParam organization integer required The organization ID. Example: 10
     *
     * @response 200 scenario="OK" {"success": true, "data": null, "message": "Success", "status": 200, "meta": {}}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     * @response 403 scenario="Forbidden" {"success": false, "message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [Organization] 999"}
     */
    public function destroy(OrganizationRequest $request, Organization $organization): ApiResponse
    {
        $organization->deleteCompletely();

        return ApiResponse::success();
    }
}
