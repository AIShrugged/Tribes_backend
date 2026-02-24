<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\LinkIdentityRequest;
use App\Http\Resources\API\v1\ProfileResource;
use App\Http\Responses\ApiResponse;
use App\Models\Profile;
use App\Services\ProfileLinkingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Group;

/**
 * @group Identities
 *
 * Manage the data sources (channels) linked to the authenticated user's account.
 * Each identity is a profile on a specific channel (google_calendar, telegram, zoom, etc.)
 * and carries the AI-collected insight data for that person on that channel.
 */
#[Group('Identities')]
class UserIdentityController extends Controller
{
    public function __construct(
        private readonly ProfileLinkingService $profileLinking,
    ) {}

    /**
     * List linked identities
     *
     * Returns all channel profiles linked to the currently authenticated user.
     * Each profile represents one data source (e.g. a Google Calendar email,
     * a Telegram account, a Zoom user ID).
     *
     * @authenticated
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "channel": "google_calendar",
     *       "channel_identifier": "user@example.com",
     *       "user_id": 42
     *     },
     *     {
     *       "id": 5,
     *       "channel": "telegram",
     *       "channel_identifier": "123456789",
     *       "user_id": 42
     *     }
     *   ],
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     */
    public function index(Request $request): ApiResponse
    {
        $profiles = $request->user()
            ->profiles()
            ->with('channel')
            ->get();

        return ApiResponse::success(data: ProfileResource::collection($profiles));
    }

    /**
     * Link a new identity
     *
     * Attaches a channel identity (email, Telegram ID, etc.) to the authenticated user.
     * If the identity profile already exists without an owner, it is claimed by the user
     * and all previously collected insight data is preserved.
     *
     * For Telegram: also updates the `telegram_users` table to link the Telegram account.
     *
     * @authenticated
     *
     *
     * @response 200 scenario="Linked (or already linked to this user)" {
     *   "success": true,
     *   "data": {
     *     "id": 5,
     *     "channel": "telegram",
     *     "channel_identifier": "123456789",
     *     "user_id": 42
     *   },
     *   "message": "Identity linked.",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 404 scenario="Channel not found" {
     *   "success": false,
     *   "data": null,
     *   "message": "Channel 'unknown' not found.",
     *   "status": 404,
     *   "meta": {}
     * }
     * @response 409 scenario="Identity belongs to another user" {
     *   "success": false,
     *   "data": null,
     *   "message": "This identity is already linked to another user.",
     *   "status": 409,
     *   "meta": {}
     * }
     * @response 422 scenario="Validation error" {
     *   "message": "The channel field is required.",
     *   "errors": {"channel": ["The channel field is required."]}
     * }
     */
    public function link(LinkIdentityRequest $request): ApiResponse
    {
        $user    = $request->user();
        $channel = $request->getChannel();
        $identifier = $request->getIdentifier();

        try {
            if ($channel === 'telegram') {
                $profile = $this->profileLinking->linkTelegramUser($user, (int) $identifier);
            } else {
                $profile = $this->profileLinking->linkIdentity($user, $channel, $identifier);
            }
        } catch (\RuntimeException $e) {
            $status = $e->getCode() ?: 400;
            return ApiResponse::error($e->getMessage(), status: $status);
        }

        $profile->load('channel');

        return ApiResponse::success(
            message: 'Identity linked.',
            data: new ProfileResource($profile),
        );
    }

    /**
     * Unlink an identity
     *
     * Detaches a channel profile from the authenticated user.
     * If insight data exists for this profile, the data is preserved and the profile
     * becomes anonymous (user_id is set to null). If no insight data exists,
     * the profile record is deleted entirely.
     *
     * @authenticated
     *
     * @urlParam profile integer required The Profile ID. Example: 5
     *
     * @response 200 scenario="Unlinked" {
     *   "success": true,
     *   "data": null,
     *   "message": "Identity unlinked.",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 403 scenario="Not the owner" {
     *   "success": false,
     *   "data": null,
     *   "message": "This action is unauthorized.",
     *   "status": 403,
     *   "meta": {}
     * }
     * @response 404 scenario="Not found" {
     *   "success": false,
     *   "data": null,
     *   "message": "Not Found",
     *   "status": 404,
     *   "meta": {}
     * }
     */
    public function unlink(Request $request, Profile $profile): ApiResponse
    {
        Gate::authorize('unlink', $profile);

        $this->profileLinking->unlinkIdentity($request->user(), $profile);

        return ApiResponse::success(message: 'Identity unlinked.');
    }
}
