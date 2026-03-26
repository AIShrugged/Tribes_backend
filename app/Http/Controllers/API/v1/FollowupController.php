<?php

namespace App\Http\Controllers\API\v1;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\FollowupRequest;
use App\Http\Resources\API\v1\FollowupResource;
use App\Http\Responses\ApiResponse;
use App\Jobs\RegenerateFollowupJob;
use App\Models\CalendarEvent;
use App\Models\Followup;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * @group Followups
 */
class FollowupController extends Controller
{
    /**
     * List followups for a team
     *
     * Returns a paginated list of AI-generated followups for all meetings belonging
     * to the specified team. Only followups visible to the authenticated user are returned.
     * The total count is returned in the `Items-Count` response header.
     *
     * @authenticated
     *
     * @urlParam team integer required The Team ID. Example: 2
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "calendar_event": {"id": 5, "title": "Q1 Planning", "start_time": "2026-02-10T09:00:00.000000Z"},
     *       "team_id": 2,
     *       "user": {"id": 1, "name": "Alice Johnson", "email": "alice@example.com"},
     *       "methodology_id": 1,
     *       "text": "Action items: 1) Finalize roadmap by Feb 20. 2) Schedule follow-up with stakeholders.",
     *       "status": "done",
     *       "created_at": "2026-02-10T10:00:00.000000Z",
     *       "updated_at": "2026-02-10T10:05:00.000000Z"
     *     }
     *   ],
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [Team] 2"}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function index(FollowupRequest $request, Team $team): ApiResponse
    {
        Gate::authorize('viewAny', [Followup::class, $team]);

        $followups = $team->followups()->owned(Auth::id());

        $count = $followups->count();

        $followups = $followups->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(FollowupResource::collection($followups), $count);
    }

    /**
     * Get followup
     *
     * Returns a single AI-generated followup by ID.
     * The authenticated user must have access to the followup.
     *
     * @authenticated
     *
     * @urlParam followup integer required The Followup ID. Example: 1
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "calendar_event": {"id": 5, "title": "Q1 Planning", "start_time": "2026-02-10T09:00:00.000000Z"},
     *     "team_id": 2,
     *     "user": {"id": 1, "name": "Alice Johnson", "email": "alice@example.com"},
     *     "methodology_id": 1,
     *     "text": "Action items: 1) Finalize roadmap by Feb 20. 2) Schedule follow-up with stakeholders.",
     *     "status": "done",
     *     "created_at": "2026-02-10T10:00:00.000000Z",
     *     "updated_at": "2026-02-10T10:05:00.000000Z"
     *   },
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [Followup] 1"}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function show(FollowupRequest $request, Followup $followup): ApiResponse
    {
        Gate::authorize('view', $followup);

        return ApiResponse::success(
            data: FollowupResource::make($followup)
        );
    }

    /**
     * Get followup for a calendar event
     *
     * Returns the latest AI-generated followup for the specified calendar event.
     * Only the followup visible to the authenticated user is returned.
     *
     * @authenticated
     *
     * @urlParam calendarEvent integer required The Calendar Event ID. Example: 5
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "calendar_event": {"id": 5, "title": "Q1 Planning", "start_time": "2026-02-10T09:00:00.000000Z"},
     *     "team_id": 2,
     *     "user": {"id": 1, "name": "Alice Johnson", "email": "alice@example.com"},
     *     "methodology_id": 1,
     *     "text": "Action items: 1) Finalize roadmap by Feb 20. 2) Schedule follow-up with stakeholders.",
     *     "status": "done",
     *     "created_at": "2026-02-10T10:00:00.000000Z",
     *     "updated_at": "2026-02-10T10:05:00.000000Z"
     *   },
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found — no followup generated yet or wrong event" {
     *   "success": false,
     *   "data": null,
     *   "message": "Not Found",
     *   "status": 404,
     *   "meta": {}
     * }
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function eventShow(FollowupRequest $request, CalendarEvent $calendarEvent): ApiResponse
    {
        $followup = $calendarEvent->followups()->owned(Auth::id())->latest()->firstOrFail();

        Gate::authorize('view', $followup);

        return ApiResponse::success(data: FollowupResource::make($followup));
    }

    /**
     * Regenerate followup
     *
     * Queues regeneration of the latest followup for the same calendar event using the team's current methodology.
     * Use when a followup is deprecated (its methodology differs from the team's current one) or needs to be refreshed.
     * The old followup is kept intact and a new followup will be created asynchronously.
     *
     * @authenticated
     *
     * @urlParam followup integer required The Followup ID to regenerate. Example: 1
     *
     * @response 202 scenario="Accepted" {
     *   "success": true,
     *   "data": {
     *     "calendar_event_id": 5,
     *     "followup_id": 1,
     *     "status": "in_progress"
     *   },
     *   "message": "Followup regeneration queued",
     *   "status": 202,
     *   "meta": {}
     * }
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [Followup] 1"}
     */
    public function regenerate(Followup $followup): ApiResponse
    {
        Gate::authorize('view', $followup);

        RegenerateFollowupJob::dispatch($followup->calendar_event_id, Auth::id());

        return ApiResponse::success(
            message: 'Followup regeneration queued',
            data: [
                'calendar_event_id' => $followup->calendar_event_id,
                'followup_id' => $followup->id,
                'status' => 'in_progress',
            ],
            status: 202,
        );
    }

    /**
     * For TESTING purposes. Remove for production
     *
     * @param Request $request
     * @return ApiResponse
     *
     * @hideFromAPIDocumentation
     */
    public function generate(Request $request): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())
            ->findOrFail($request->route('calendar_event_id'));

        $user = Auth::user();
        $teams = $user->teams;

        if ($teams->isEmpty()) {
            throw new AppException('User has no teams', 'NO_TEAMS');
        }

        // Для теста генерируем для первой команды
        $team = $teams->first();

        $followup = app(FollowupService::class)->generate(
            $calendarEvent,
            $team,
            $user
        );

        return ApiResponse::success(
            data: $followup
        );
    }
}
