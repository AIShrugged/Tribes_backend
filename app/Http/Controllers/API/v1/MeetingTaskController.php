<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\MeetingTaskRequest;
use App\Http\Resources\API\v1\MeetingTaskResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Services\Meeting\MeetingTaskService;
use Illuminate\Support\Facades\Auth;

/**
 * @group Calendar
 */
class MeetingTaskController extends Controller
{
    /**
     * List meeting tasks
     *
     * Returns a paginated list of AI-extracted action items for a calendar event.
     * The total count is returned in the `Items-Count` response header.
     *
     * @subgroup Meeting Tasks
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
     *       "assignee_id": 7,
     *       "name": "Prepare Q1 budget report",
     *       "description": "Compile financial data from all departments",
     *       "assignee_name": "Alice Johnson",
     *       "due_date": "2026-02-28",
     *       "status": "open",
     *       "created_at": "2026-02-10T10:05:00.000000Z",
     *       "updated_at": "2026-02-10T10:05:00.000000Z"
     *     }
     *   ],
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 404 scenario="Not Found" {"message": "No query results for model [CalendarEvent] 5"}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function index(MeetingTaskRequest $request): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())
            ->findOrFail($request->getCalendarEventId());

        $tasks = $calendarEvent->issues();

        $count = $tasks->count();

        $tasks = $tasks->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(MeetingTaskResource::collection($tasks), $count);
    }

    /**
     * Get meeting task
     *
     * Returns a single AI-extracted meeting task by ID.
     *
     * @subgroup Meeting Tasks
     * @authenticated
     *
     * @urlParam task_id integer required The Task ID. Example: 1
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "calendar_event_id": 5,
     *     "assignee_id": 7,
     *     "name": "Prepare Q1 budget report",
     *     "description": "Compile financial data from all departments",
     *     "assignee_name": "Alice Johnson",
     *     "due_date": "2026-02-28",
     *     "status": "open",
     *     "created_at": "2026-02-10T10:05:00.000000Z",
     *     "updated_at": "2026-02-10T10:05:00.000000Z"
     *   },
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 404 scenario="Not Found" {"message": "No query results for model [MeetingTask] 1"}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function show(MeetingTaskRequest $request): ApiResponse
    {
        $task = Issue::findOrFail($request->getTaskId());

        return ApiResponse::success(data: MeetingTaskResource::make($task));
    }

    /**
     * Extract meeting tasks (for testing)
     *
     * @subgroup Meeting Tasks
     * @authenticated
     * @hideFromAPIDocumentation
     */
    public function generate(MeetingTaskRequest $request, MeetingTaskService $service): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())
            ->findOrFail($request->getCalendarEventId());

        $tasks = $service->extract($calendarEvent);

        return ApiResponse::list(MeetingTaskResource::collection($tasks), $tasks->count());
    }
}
