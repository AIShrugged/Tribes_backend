<?php

namespace App\Http\Controllers\API\v1;

use App\Events\CalendarEventChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\RequireBotRequest;
use App\Http\Resources\API\v1\CalendarEventResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use Illuminate\Support\Facades\Auth;

/**
 * @group Calendar
 * @subgroup Events
 */
class BotController extends Controller
{
    /**
     * Set bot requirement for event
     *
     * Marks whether the recording bot should join the specified calendar event
     * for the current user's source. The bot is active if any participant requires it.
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
     * @response 404 scenario="Not Found" {"message": "No query results for model [CalendarEvent] 5"}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function require(RequireBotRequest $request): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())
            ->findOrFail($request->getCalendarEventId());

        // Update required_bot for the current user's source in the pivot
        $source = $calendarEvent->sources()
            ->where('user_id', Auth::id())
            ->first();

        if ($source) {
            $calendarEvent->sources()->updateExistingPivot($source->id, [
                'required_bot' => $request->getRequiredBot(),
            ]);
        }

        CalendarEventChanged::dispatch($calendarEvent, true);

        return ApiResponse::success(
            data: CalendarEventResource::make($calendarEvent),
        );
    }
}
