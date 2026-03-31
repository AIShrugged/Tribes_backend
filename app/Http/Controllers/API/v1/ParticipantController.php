<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\ParticipantRequest;
use App\Http\Resources\API\v1\ParticipantResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * @group Calendar
 * @subgroup Events
 */
class ParticipantController extends Controller
{
    /**
     * List participants
     *
     * Returns a paginated list of participants for a calendar event.
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
     *       "id": 1,
     *       "calendar_event_id": 5,
     *       "profile": {"id": 3, "channel": "GOOGLE", "channel_identifier": "alice@example.com", "user_id": 1},
     *       "name": "Alice Johnson"
     *     }
     *   ],
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 404 scenario="Not Found" {"message": "No query results for model [CalendarEvent] 5"}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function index(ParticipantRequest $request): ApiResponse
    {
        $calendarEvent = $this->findVisibleCalendarEvent($request->getCalendarEventId());

        $participants = $calendarEvent->participants();

        $count = $participants->count();

        $participants = $participants->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(ParticipantResource::collection($participants), $count);
    }

    /**
     * Assign profile to participant
     *
     * Links a meeting participant to an existing contact profile.
     *
     * @authenticated
     *
     * @urlParam calendar_event_id integer required The Calendar Event ID. Example: 5
     * @urlParam participant_id integer required The Participant ID. Example: 1
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "calendar_event_id": 5,
     *     "profile": {"id": 3, "channel": "GOOGLE", "channel_identifier": "alice@example.com", "user_id": 1},
     *     "name": "Alice Johnson"
     *   },
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 404 scenario="Not Found" {"message": "No query results for model [Participant] 1"}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function setProfile(ParticipantRequest $request): ApiResponse
    {
        $calendarEvent = $this->findVisibleCalendarEvent($request->getCalendarEventId());

        $participant = $calendarEvent->participants()->findOrFail($request->getParticipantId());

        $profile = $calendarEvent->profiles()->findOrFail($request->getProfileId());

        $participant->profile()->associate($profile);
        $participant->save();

        return ApiResponse::success(
            data: ParticipantResource::make($participant),
        );
    }

    private function findVisibleCalendarEvent(int $calendarEventId): CalendarEvent
    {
        return CalendarEvent::query()
            ->whereKey($calendarEventId)
            ->where(function (Builder $builder): void {
                $builder->whereHas('sources', function (Builder $sources): void {
                    $sources->where('user_id', Auth::id());
                })->orWhereHas('followups', function (Builder $followups): void {
                    $followups->owned(Auth::id());
                });
            })
            ->firstOrFail();
    }
}
