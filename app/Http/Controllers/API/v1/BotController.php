<?php

namespace App\Http\Controllers\API\v1;

use App\Events\CalendarEventChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\JoinBotNowRequest;
use App\Http\Requests\API\v1\RequireBotRequest;
use App\Http\Resources\API\v1\CalendarEventResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use App\Services\Recall\BotSchedulingService;
use Illuminate\Support\Facades\Auth;

/**
 * @group Calendar
 * @subgroup Events
 */
class BotController extends Controller
{
    public function __construct(
        private readonly BotSchedulingService $botSchedulingService,
    ) {
    }

    /**
     * Set bot requirement for event
     *
     * Marks whether the recording bot should join the specified calendar event
     * for the current user's source. When enabling the bot, `organization_id`
     * records which organization the bot was connected from — the meeting then
     * shows up in that organization's calendar. Only the meeting organizer may
     * manage the bot, and they must belong to the selected organization.
     *
     * @authenticated
     *
     * @urlParam calendar_event_id integer required The Calendar Event ID. Example: 5
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": {
     *     "id": 5,
     *     "platform": "google_meet",
     *     "url": "https://meet.google.com/abc-defg-hij",
     *     "title": "Q1 Planning",
     *     "description": "Quarterly planning session",
     *     "starts_at": "2026-02-10T09:00:00.000000Z",
     *     "ends_at": "2026-02-10T10:00:00.000000Z",
     *     "creator_user_id": 1,
     *     "required_bot": true
     *   },
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 403 scenario="Forbidden" {"message": "Only the meeting organizer can manage the bot."}
     * @response 403 scenario="Not an organization member" {"message": "You must be a member of the selected organization."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [CalendarEvent] 5"}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function require(RequireBotRequest $request): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())
            ->findOrFail($request->getCalendarEventId());

        abort_unless(
            $calendarEvent->creator_user_id === Auth::id(),
            403,
            'Only the meeting organizer can manage the bot.',
        );

        $requiredBot = $request->getRequiredBot();
        $organizationId = $request->getOrganizationId();

        if ($requiredBot) {
            abort_unless(
                Auth::user()->organizations()->whereKey($organizationId)->exists(),
                403,
                'You must be a member of the selected organization.',
            );
        }

        // Update required_bot for the current user's source in the pivot. When
        // enabling, record the organization the bot was connected from so the
        // meeting appears in that organization's calendar; clear it when disabling.
        $source = $calendarEvent->sources()
            ->where('user_id', Auth::id())
            ->first();

        if ($source) {
            $calendarEvent->sources()->updateExistingPivot($source->id, [
                'required_bot'    => $requiredBot,
                'organization_id' => $requiredBot ? $organizationId : null,
            ]);
        }

        CalendarEventChanged::dispatch($calendarEvent, true);

        return ApiResponse::success(
            data: CalendarEventResource::make($calendarEvent),
        );
    }

    /**
     * Send bot to an already running meeting
     *
     * Creates a Recall bot directly by meeting URL, bypassing Recall calendar
     * event lookup. Use this when the meeting has already started and the bot
     * needs to join immediately. `organization_id` records which organization the
     * bot was connected from so the meeting shows up in that organization's
     * calendar. Only the organizer may do this, and they must belong to the
     * selected organization.
     *
     * @authenticated
     *
     * @urlParam calendar_event_id integer required The Calendar Event ID. Example: 5
     *
     * @response 403 scenario="Forbidden" {"message": "Only the meeting organizer can manage the bot."}
     * @response 403 scenario="Not an organization member" {"message": "You must be a member of the selected organization."}
     */
    public function joinNow(JoinBotNowRequest $request): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())
            ->findOrFail($request->getCalendarEventId());

        abort_unless(
            $calendarEvent->creator_user_id === Auth::id(),
            403,
            'Only the meeting organizer can manage the bot.',
        );

        $organizationId = $request->getOrganizationId();

        abort_unless(
            Auth::user()->organizations()->whereKey($organizationId)->exists(),
            403,
            'You must be a member of the selected organization.',
        );

        $source = $calendarEvent->sources()
            ->where('user_id', Auth::id())
            ->first();

        if ($source) {
            $calendarEvent->sources()->updateExistingPivot($source->id, [
                'required_bot'    => true,
                'organization_id' => $organizationId,
            ]);
        }

        $this->botSchedulingService->joinMeetingNow($calendarEvent);

        return ApiResponse::success(
            data: CalendarEventResource::make($calendarEvent->fresh('bot')),
        );
    }
}
