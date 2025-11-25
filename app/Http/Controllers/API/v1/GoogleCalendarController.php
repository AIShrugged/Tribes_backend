<?php

namespace App\Http\Controllers\API\v1;

use App\Domain\Errors\OAuthInvalidStateError;
use App\Domain\Errors\SourceExistsError;
use App\Enums\SourceAuthType;
use App\Enums\SourceType;
use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\GoogleCalendarRequest;
use App\Http\Resources\API\v1\SourceResource;
use App\Http\Responses\ApiResponse;
use App\Jobs\ParseEventsJob;
use App\Models\OAuthState;
use App\Models\Source;
use App\Models\SourceOauth;
use App\Services\GoogleOAuthService;
use App\Services\RecallCalendarService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * @group Calendar
 */
class GoogleCalendarController extends Controller
{
    /**
     * Attach google calendar
     *
     * @param Request $request
     * @return ApiResponse
     * @authenticated
     */
    public function attach(Request $request): ApiResponse
    {
        return ApiResponse::success(data: [
            'redirect' => app(GoogleOAuthService::class)->redirect(Auth::id())
        ]);
    }

    public function callback(GoogleCalendarRequest $request): ApiResponse
    {
        $oauthState = OAuthState::firstWhere('state', $request->getState());

        if (!$oauthState || !$oauthState->isValid()) {
            throw AppException::fromErrorClass(OAuthInvalidStateError::class, 400);
        }

        try {
            DB::beginTransaction();
            $oauthDTO = app(GoogleOAuthService::class)->callback($oauthState, $request->getCode());

            if (Source::firstWhere([
                'user_id'  => $oauthState->user_id,
                'identity' => $oauthDTO->email,
                'type'     => SourceType::GOOGLE_CALENDAR->value
            ])) {
                throw AppException::fromErrorClass(SourceExistsError::class);
            }

            $sourceDTO = RecallCalendarService::attach($oauthDTO, SourceType::GOOGLE_CALENDAR);

            $source = Source::create([
                'user_id'     => $oauthState->user_id,
                'external_id' => $sourceDTO->externalId,
                'identity'    => $sourceDTO->identity,
                'auth_type'   => SourceAuthType::OAUTH2->value,
                'type'        => SourceType::GOOGLE_CALENDAR->value
            ]);

            SourceOauth::create([
                'source_id'     => $source->id,
                'access_token'  => $oauthDTO->accessToken,
                'refresh_token' => $oauthDTO->refreshToken,
                'expires_at'    => Carbon::now()->addSeconds($oauthDTO->expiresIn),
                'email'         => $oauthDTO->email,
            ]);

            DB::commit();
            return ApiResponse::success(data: SourceResource::make($source));
        } catch (\Exception $exception) {
            DB::rollBack();

            throw $exception;
        } finally {
            $oauthState->delete();
        }
    }
}
