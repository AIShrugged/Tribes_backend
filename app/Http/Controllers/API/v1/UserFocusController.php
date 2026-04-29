<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\UserFocusRequest;
use App\Http\Resources\API\v1\UserFocusResource;
use App\Http\Responses\ApiResponse;
use App\Models\Profile;
use App\Services\Agent\MemoryService;
use App\Services\UserFocusService;
use Illuminate\Http\Request;

/**
 * @group User Focus
 *
 * Manage the authenticated user's active focus or priority.
 */
class UserFocusController extends Controller
{
    public function __construct(
        private readonly UserFocusService $userFocusService,
        private readonly MemoryService $memoryService,
    ) {}

    /**
     * Get focus
     *
     * Returns the authenticated user's active focus record, or null if none is set.
     *
     * @authenticated
     *
     * @response 200 scenario="Focus active" {
     *   "success": true,
     *   "data": {"focus_text": "Ship v2.0", "deadline": "2026-04-25", "expires_at": "2026-04-25T23:59:59+03:00"}
     * }
     * @response 200 scenario="No focus" {"success": true, "data": null}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function show(Request $request): ApiResponse
    {
        $profile = $this->resolveProfile($request);

        if (! $profile) {
            return ApiResponse::success(data: null);
        }

        $focus = $this->userFocusService->getFocus($profile);

        return ApiResponse::success(data: $focus ? UserFocusResource::make($focus) : null);
    }

    /**
     * Set focus
     *
     * Creates or replaces the authenticated user's active focus.
     *
     * @authenticated
     *
     * @response 200 scenario="Created" {
     *   "success": true,
     *   "data": {"focus_text": "Ship v2.0", "deadline": "2026-04-25", "expires_at": "2026-04-25T23:59:59+03:00"}
     * }
     * @response 422 scenario="Validation error" {"message": "The focus text field is required."}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function update(UserFocusRequest $request): ApiResponse
    {
        $profile = $this->resolveProfile($request);

        if (! $profile) {
            return ApiResponse::error('Profile not found', status: 404);
        }

        $focus = $this->userFocusService->setFocus(
            $profile,
            $request->getFocusText(),
            $request->getDeadline(),
            $request->getIssueIds(),
        );

        $this->invalidateAllChannels($profile);

        return ApiResponse::success(data: UserFocusResource::make($focus));
    }

    /**
     * Clear focus
     *
     * Deletes the authenticated user's active focus.
     *
     * @authenticated
     *
     * @response 200 scenario="Cleared" {"success": true, "data": null}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function destroy(Request $request): ApiResponse
    {
        $profile = $this->resolveProfile($request);

        if (! $profile) {
            return ApiResponse::success(data: null);
        }

        $this->userFocusService->clearFocus($profile);

        $this->invalidateAllChannels($profile);

        return ApiResponse::success(data: null);
    }

    private function resolveProfile(Request $request): ?Profile
    {
        return Profile::where('user_id', $request->user()->id)->first();
    }

    private function invalidateAllChannels(Profile $profile): void
    {
        $this->memoryService->invalidateMemoryCache($profile, 'web');
        $this->memoryService->invalidateMemoryCache($profile, 'telegram');
    }
}