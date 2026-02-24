<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\TranscriptRequest;
use App\Http\Resources\API\v1\TranscriptEntryResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @group Calendar
 */
class TranscriptController extends Controller
{
    /**
     * Get transcript
     *
     * Returns the paginated transcript of a calendar event — a timestamped list of
     * speech segments, each attributed to a meeting participant.
     * The total count is returned in the `Items-Count` response header.
     *
     * @subgroup Transcript
     * @authenticated
     *
     * @urlParam calendar_event_id integer required The Calendar Event ID. Example: 5
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "participant": {
     *         "id": 3,
     *         "name": "Alice Johnson",
     *         "email": "alice@example.com"
     *       },
     *       "text": "Let's start with the Q1 budget review.",
     *       "start_relative": 0.0,
     *       "end_relative": 4.5,
     *       "start_absolute": "2026-02-10T09:00:00.000000Z",
     *       "end_absolute": "2026-02-10T09:00:04.000000Z"
     *     }
     *   ],
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 404 scenario="Not Found" {
     *   "success": false,
     *   "data": null,
     *   "message": "Not Found",
     *   "status": 404,
     *   "meta": {}
     * }
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function index(TranscriptRequest $request): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())
            ->findOrFail($request->getCalendarEventId());

        $entries = $calendarEvent->transcriptEntries();

        $count = $entries->count();

        $entries = $entries->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(TranscriptEntryResource::collection($entries), $count);
    }
}
