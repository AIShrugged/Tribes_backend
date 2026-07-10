<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\OrganizationCalendarIndexRequest;
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
     * Returns a paginated list of the given organization's meetings — those where
     * the meeting creator connected the recording bot from this organization
     * (required_bot = true and the pivot's organization_id matches). The
     * authenticated user must be a member of the organization. Not scoped to the
     * user: it returns the organization's meetings regardless of who created them.
     *
     * @subgroup Organization
     * @authenticated
     * @queryParam organization_id integer required The organization whose calendar to list. Example: 42
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
     * @response 403 scenario="Not an organization member" {"message": "You must be a member of this organization."}
     */
    public function index(OrganizationCalendarIndexRequest $request): ApiResponse
    {
        $organizationId = $request->getOrganizationId();

        abort_unless(
            Auth::user()->organizations()->whereKey($organizationId)->exists(),
            403,
            'You must be a member of this organization.',
        );

        $query = CalendarEvent::query()
            ->forOrganizationCalendar($organizationId)
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
