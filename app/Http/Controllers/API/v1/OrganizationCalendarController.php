<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\OrganizationCalendarRequest;
use App\Http\Resources\API\v1\CalendarEventResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;

/**
 * @group Calendar
 */
class OrganizationCalendarController extends Controller
{
    /**
     * List organization bot meetings
     *
     * Returns a paginated list of meetings across the organization where the bot
     * was requested (required_bot = true). Not scoped to the authenticated user —
     * returns all meetings from all members of the user's organizations.
     *
     * @subgroup Organization
     * @authenticated
     * @queryParam date_from string Filter meetings starting on or after this date (YYYY-MM-DD). Example: 2026-04-06
     * @queryParam date_to string Filter meetings starting on or before this date (YYYY-MM-DD, must be >= date_from). Example: 2026-04-12
     * @queryParam offset integer Number of items to skip. Example: 0
     * @queryParam limit integer Maximum items to return (1–100). Example: 20
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
     *       "starts_at": "2026-04-09T10:00:00.000000Z",
     *       "ends_at": "2026-04-09T11:00:00.000000Z",
     *       "creator_user_id": 3,
     *       "required_bot": true,
     *       "has_summary": true
     *     }
     *   ],
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function index(OrganizationCalendarRequest $request): ApiResponse
    {
        $user = Auth::user();
        $orgIds = $user->organizations()->pluck('organizations.id');

        $query = CalendarEvent::query()
            ->whereHas('sources', function (Builder $q) use ($orgIds) {
                $q->where('required_bot', true)
                  ->whereHas('user', function (Builder $uq) use ($orgIds) {
                      $uq->whereHas('organizations', function (Builder $oq) use ($orgIds) {
                          $oq->whereIn('organizations.id', $orgIds);
                      });
                  });
            })
            ->with(['meetingSummary'])
            ->when($request->getDateFrom(), fn (Builder $q, $date) => $q->where('starts_at', '>=', $date))
            ->when($request->getDateTo(),   fn (Builder $q, $date) => $q->where('starts_at', '<=', $date))
            ->orderBy('starts_at', 'desc');

        $count = $query->count();

        $events = $query
            ->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(CalendarEventResource::collection($events), $count);
    }
}
