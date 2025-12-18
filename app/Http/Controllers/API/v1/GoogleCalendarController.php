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
use App\Models\OAuthState;
use App\Models\Source;
use App\Models\SourceOauth;
use App\Services\GoogleOAuthService;
use App\Services\RecallCalendarService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
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


    /**
     * @param GoogleCalendarRequest $request
     * @return RedirectResponse
     * @throws \Exception
     * @hideFromAPIDocumentation
     */
    public function callback(GoogleCalendarRequest $request): RedirectResponse
    {
        $oauthState = OAuthState::firstWhere('state', $request->getState());

        if (!$oauthState || !$oauthState->isValid()) {
            throw AppException::fromErrorClass(OAuthInvalidStateError::class, 400);
        }

        try {
            DB::beginTransaction();
            $oauthDTO = app(GoogleOAuthService::class)->callback($oauthState, $request->getCode());

            $source = Source::firstWhere([
                'user_id'  => $oauthState->user_id,
                'identity' => $oauthDTO->email,
                'type'     => SourceType::GOOGLE_CALENDAR->value
            ]);

            if ($source) {
                RecallCalendarService::reAttach($oauthDTO, SourceType::GOOGLE_CALENDAR, $source->external_id);
            } else {
                $sourceDTO = RecallCalendarService::attach($oauthDTO, SourceType::GOOGLE_CALENDAR);

                $source = Source::create([
                    'user_id'     => $oauthState->user_id,
                    'external_id' => $sourceDTO->externalId,
                    'identity'    => $sourceDTO->identity,
                    'auth_type'   => SourceAuthType::OAUTH2->value,
                    'type'        => SourceType::GOOGLE_CALENDAR->value
                ]);
            }

            SourceOauth::updateOrCreate(['source_id' => $source->id], [
                'access_token'  => $oauthDTO->accessToken,
                'refresh_token' => $oauthDTO->refreshToken,
                'expires_at'    => Carbon::now()->addSeconds($oauthDTO->expiresIn),
                'email'         => $oauthDTO->email,
            ]);

            DB::commit();
            return redirect(config('app.frontend_url') . '/dashboard/calendar?attached=1');
        } catch (\Exception $exception) {
            DB::rollBack();

            throw $exception;
        } finally {
            $oauthState->delete();
        }
    }
}
