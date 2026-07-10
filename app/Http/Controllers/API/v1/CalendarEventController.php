<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\CalendarEventRequest;
use App\Http\Requests\API\v1\OrganizationCalendarRequest;
use App\Http\Resources\API\v1\CalendarEventResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * @group Calendar
 */
class CalendarEventController extends Controller
{
    /**
     * List calendar events
     *
     * Returns a paginated list of calendar events owned by the authenticated user.
     * The total count is returned in the `Items-Count` response header.
     *
     * @subgroup Events
     *
     * @authenticated
     *
     * @queryParam date string Filter events by a single day in YYYY-MM-DD format. Example: 2026-04-06
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 5,
     *       "platform": "google_meet",
     *       "url": "https://meet.google.com/abc-defg-hij",
     *       "title": "Q1 Planning",
     *       "description": "Quarterly planning session",
     *       "starts_at": "2026-02-10T09:00:00.000000Z",
     *       "ends_at": "2026-02-10T10:00:00.000000Z",
     *       "external_id": "ext_abc123",
     *       "source_id": 1,
     *       "required_bot": true
     *     }
     *   ],
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function index(CalendarEventRequest $request): ApiResponse
    {
        $calendarEvents = CalendarEvent::owned(Auth::id());

        if ($request->getDate()) {
            $calendarEvents->whereBetween('starts_at', [
                $request->getDate()->copy()->startOfDay(),
                $request->getDate()->copy()->endOfDay(),
            ]);
        }

        $calendarEvents = match ($request->getScope()) {
            'past' => $calendarEvents->where('ends_at', '<', now()),
            'upcoming' => $calendarEvents->where('starts_at', '>', now()),
            default => $calendarEvents,
        };

        if ($teamId = $request->getTeamId()) {
            $calendarEvents->where(function ($q) use ($teamId) {
                $q->whereHas('sources.user.teams', fn ($inner) => $inner->where('teams.id', $teamId))
                    ->orWhereHas('participants.profile.user.teams', fn ($inner) => $inner->where('teams.id', $teamId));
            });
        }

        if ($participantId = $request->getParticipantId()) {
            $calendarEvents->whereHas('participants', fn ($q) => $q->where('participants.id', $participantId));
        }

        if ($userId = $request->getUserId()) {
            $calendarEvents->where(function ($q) use ($userId) {
                $q->whereHas('sources', fn ($inner) => $inner->where('user_id', $userId))
                    ->orWhereHas('profiles', fn ($inner) => $inner->where('user_id', $userId));
            });
        }

        $count = $calendarEvents->count();

        $calendarEvents = $calendarEvents->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(CalendarEventResource::collection($calendarEvents), $count);
    }

    /**
     * Get calendar event
     *
     * Returns a single calendar event by ID. The event must belong to the authenticated user.
     *
     * @subgroup Events
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
     *     "external_id": "ext_abc123",
     *     "source_id": 1,
     *     "required_bot": true
     *   },
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
    public function show(CalendarEventRequest $request): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())
            ->findOrFail($request->getEventId());

        return ApiResponse::success(data: CalendarEventResource::make($calendarEvent));
    }

    /**
     * List meetings the user can view (second-brain protocol/agenda picker)
     *
     * Like the owned list, but for organizations the user manages it also includes
     * every meeting visible to that org — manual transcript uploads and teammates'
     * meetings the second brain processes. Ordered newest first. Use this instead of
     * the owned list to populate the meeting picker on the "Протокол и агенда" tab.
     *
     * @subgroup Events
     *
     * @authenticated
     *
     * @queryParam date_from string Filter meetings starting on or after this date (YYYY-MM-DD). Example: 2026-06-01
     * @queryParam date_to string Filter meetings starting on or before this date (YYYY-MM-DD). Example: 2026-07-31
     * @queryParam offset integer Number of items to skip. Example: 0
     * @queryParam limit integer Maximum items to return (1–100). Example: 50
     */
    public function viewable(OrganizationCalendarRequest $request): ApiResponse
    {
        $query = CalendarEvent::viewableBy(Auth::id())
            ->with(['meetingSummary'])
            ->when($request->getDateFrom(), fn (Builder $q, $date) => $q->where('starts_at', '>=', $date))
            ->when($request->getDateTo(), fn (Builder $q, $date) => $q->where('starts_at', '<=', $date))
            ->orderBy('starts_at', 'desc');

        $count = $query->count();

        $events = $query
            ->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(CalendarEventResource::collection($events), $count);
    }
}
