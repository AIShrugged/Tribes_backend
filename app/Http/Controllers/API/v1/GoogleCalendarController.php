<?php

namespace App\Http\Controllers\API\v1;

use App\Domain\Errors\OAuthInvalidStateError;
use App\Enums\SourceAuthType;
use App\Enums\SourceType;
use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\GoogleCalendarRequest;
use App\Http\Responses\ApiResponse;
use App\Models\OAuthState;
use App\Models\Organization;
use App\Models\Source;
use App\Models\SourceOauth;
use App\Models\User;
use App\Services\GoogleOAuthService;
use App\Services\ProfileLinkingService;
use App\Services\Recall\CalendarEventSyncService;
use App\Services\RecallCalendarService;
use App\Services\RecallEventService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
        $request->validate([
            'organization_id' => 'required|integer|exists:organizations,id',
        ]);

        $organization = Organization::findOrFail($request->organization_id);
        Gate::authorize('view', $organization);

        return ApiResponse::success(data: [
            'redirect' => app(GoogleOAuthService::class)->redirect(Auth::id(), $request->organization_id),
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
            $oauthDTO = app(GoogleOAuthService::class)->callback($oauthState, $request->getCode());

            [$source, $shouldSyncUpcomingMeetings] = DB::transaction(function () use ($oauthDTO, $oauthState): array {
                $source = Source::withTrashed()->firstWhere([
                    'user_id'  => $oauthState->user_id,
                    'identity' => $oauthDTO->email,
                    'type'     => SourceType::GOOGLE_CALENDAR->value,
                ]);
                $shouldSyncUpcomingMeetings = (bool) $source && !$source->trashed();

                if ($source) {
                    if ($source->trashed()) {
                        $sourceDTO = RecallCalendarService::attach($oauthDTO, SourceType::GOOGLE_CALENDAR);

                        $source->restore();
                        $source->update([
                            'external_id'     => $sourceDTO->externalId,
                            'identity'        => $sourceDTO->identity,
                            'auth_type'       => SourceAuthType::OAUTH2->value,
                            'type'            => SourceType::GOOGLE_CALENDAR->value,
                            'is_connected'    => true,
                            'organization_id' => $oauthState->organization_id,
                        ]);
                    } else {
                        RecallCalendarService::reAttach($oauthDTO, SourceType::GOOGLE_CALENDAR, $source->external_id);

                        $source->update([
                            'identity'        => $oauthDTO->email,
                            'auth_type'       => SourceAuthType::OAUTH2->value,
                            'type'            => SourceType::GOOGLE_CALENDAR->value,
                            'is_connected'    => true,
                            'organization_id' => $oauthState->organization_id,
                        ]);
                    }
                } else {
                    $sourceDTO = RecallCalendarService::attach($oauthDTO, SourceType::GOOGLE_CALENDAR);

                    $source = Source::create([
                        'user_id'         => $oauthState->user_id,
                        'external_id'     => $sourceDTO->externalId,
                        'identity'        => $sourceDTO->identity,
                        'auth_type'       => SourceAuthType::OAUTH2->value,
                        'type'            => SourceType::GOOGLE_CALENDAR->value,
                        'is_connected'    => true,
                        'organization_id' => $oauthState->organization_id,
                    ]);
                }

                SourceOauth::updateOrCreate(['source_id' => $source->id], [
                    'access_token'  => $oauthDTO->accessToken,
                    'refresh_token' => $oauthDTO->refreshToken,
                    'expires_at'    => Carbon::now()->addSeconds($oauthDTO->expiresIn),
                    'email'         => $oauthDTO->email,
                ]);

                return [$source, $shouldSyncUpcomingMeetings];
            });

            // Re-link any gc profile that was orphaned (user_id=null) during a previous disconnect.
            app(ProfileLinkingService::class)->linkByEmail(User::findOrFail($oauthState->user_id));

            if ($shouldSyncUpcomingMeetings) {
                $this->syncUpcomingMeetings($source);
            }

            return redirect(config('app.frontend_url') . '/dashboard/calendar?attached=1');
        } catch (\Exception $exception) {
            throw $exception;
        } finally {
            $oauthState->delete();
        }
    }

    private function syncUpcomingMeetings(Source $source): void
    {
        $eventService = new RecallEventService($source);
        $syncService = app(CalendarEventSyncService::class);

        foreach ($eventService->getAllByCalendar() as $eventDTO) {
            $syncService->sync($source, $eventDTO, []);
        }
    }
}
