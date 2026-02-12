<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\MeetingTaskRequest;
use App\Http\Resources\API\v1\MeetingTaskResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use App\Models\MeetingTask;
use App\Services\Meeting\MeetingTaskService;
use Illuminate\Support\Facades\Auth;

/**
 * @group Calendar
 */
class MeetingTaskController extends Controller
{
    /**
     * Get list of meeting tasks
     *
     * @subgroup Meeting Tasks
     * @authenticated
     */
    public function index(MeetingTaskRequest $request): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())
            ->findOrFail($request->getCalendarEventId());

        $tasks = $calendarEvent->meetingTasks();

        $count = $tasks->count();

        $tasks = $tasks->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(MeetingTaskResource::collection($tasks), $count);
    }

    /**
     * Get specific meeting task
     *
     * @subgroup Meeting Tasks
     * @authenticated
     */
    public function show(MeetingTaskRequest $request): ApiResponse
    {
        $task = MeetingTask::whereHas('calendarEvent', fn($q) => $q->owned(Auth::id()))
            ->findOrFail($request->getTaskId());

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
