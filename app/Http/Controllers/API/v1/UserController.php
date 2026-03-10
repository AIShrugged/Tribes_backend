<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\UpdateUserProfileRequest;
use App\Http\Resources\API\v1\UserResource;
use App\Http\Responses\ApiResponse;
use App\Services\UserProfileService;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Group;

#[Group('User')]
class UserController extends Controller
{
    public function __construct(
        private readonly UserProfileService $userProfileService,
    ) {}

    /**
     * Update profile
     *
     * Update the authenticated user's display name and/or password.
     * At least one of `name` or `password` must be provided.
     * When changing the password, `current_password` is required.
     *
     * @authenticated
     *
     * @response 200 scenario="Name updated" {
     *   "success": true,
     *   "data": {"id": 1, "name": "Alice Johnson", "email": "alice@example.com"},
     *   "message": "Success"
     * }
     * @response 422 scenario="Wrong current password" {
     *   "success": false,
     *   "message": "The current password is incorrect.",
     *   "meta": {"error_code": "INVALID_CURRENT_PASSWORD"}
     * }
     * @response 422 scenario="Validation error" {
     *   "message": "The name field must be a string."
     * }
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    #[Authenticated]
    public function update(UpdateUserProfileRequest $request): ApiResponse
    {
        $updatedUser = $this->userProfileService->update(
            user: $request->user(),
            name: $request->getName(),
            currentPassword: $request->getCurrentPassword(),
            newPassword: $request->getPassword(),
        );

        return ApiResponse::success(data: UserResource::make($updatedUser));
    }
}
