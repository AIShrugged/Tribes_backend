<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\ProfileRequest;
use App\Http\Resources\API\v1\ProfileResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @group Calendar
 * @subgroup Events
 */
class ProfileController extends Controller
{

    /**
     * List profiles for calendar event
     *
     * Returns a paginated list of contact profiles associated with a calendar event.
     * The total count is returned in the `Items-Count` response header.
     *
     * @authenticated
     *
     * @urlParam calendar_event_id integer required The Calendar Event ID. Example: 5
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 3,
     *       "channel": "GOOGLE",
     *       "channel_identifier": "alice@example.com",
     *       "user_id": 1
     *     }
     *   ],
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 404 scenario="Not Found" {"message": "No query results for model [CalendarEvent] 5"}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function index(ProfileRequest $request): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())
            ->findOrFail($request->getCalendarEventId());

        $profiles = $calendarEvent->profiles();

        $count = $profiles->count();

        $profiles = $profiles->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(ProfileResource::collection($profiles), $count);
    }
}
