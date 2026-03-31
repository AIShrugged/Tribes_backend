<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\API\v1\MeetingTaskResource;
use App\Http\Resources\API\v1\UpcomingAgendaResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\UpcomingAgenda;
use Illuminate\Support\Facades\Auth;

class UpcomingAgendaController extends Controller
{
    /**
     * Get upcoming agenda
     *
     * Returns the latest post-meeting upcoming agenda generated for the authenticated user.
     *
     * @authenticated
     */
    public function show(): ApiResponse
    {
        $agenda = UpcomingAgenda::query()
            ->where('user_id', Auth::id())
            ->with('sourceCalendarEvent.participants')
            ->first();

        if (! $agenda) {
            return ApiResponse::success(data: null);
        }

        return ApiResponse::success(data: UpcomingAgendaResource::make($agenda));
    }

    /**
     * Get latest meeting tasks
     *
     * Returns tasks from the most recent calendar event (that the user has access to) which has tasks.
     * Falls back to earlier meetings if the latest has none.
     *
     * @authenticated
     */
    public function latestTasks(): ApiResponse
    {
        $userId = Auth::id();

        $eventIds = CalendarEvent::query()
            ->where(function ($q) use ($userId) {
                $q->whereHas('sources', fn ($q) => $q->where('user_id', $userId))
                    ->orWhere('creator_user_id', $userId)
                    ->orWhereHas('participants', fn ($q) => $q->whereHas(
                        'profile',
                        fn ($q) => $q->where('user_id', $userId),
                    ));
            })
            ->where('starts_at', '<', now())
            ->orderBy('starts_at', 'desc')
            ->pluck('id');

        $latestEvent = CalendarEvent::query()
            ->whereIn('id', $eventIds)
            ->whereHas('issues', fn ($q) => $q->where('assignee_id', $userId))
            ->orderBy('starts_at', 'desc')
            ->first();

        if (! $latestEvent) {
            return ApiResponse::success(data: [
                'meeting_title' => null,
                'meeting_date'  => null,
                'meeting_id'    => null,
                'tasks'         => [],
            ]);
        }

        $tasks = $latestEvent->issues()
            ->where('assignee_id', $userId)
            ->orderBy('status')
            ->orderBy('due_date')
            ->get();

        $otherTasks = collect();
        $remaining = 5 - $tasks->count();

        if ($remaining > 0) {
            $otherTasks = Issue::query()
                ->where('assignee_id', $userId)
                ->whereNotIn('id', $tasks->pluck('id'))
                ->whereIn('status', ['open', 'in_progress'])
                ->orderBy('due_date')
                ->orderBy('created_at', 'desc')
                ->limit($remaining)
                ->get();
        }

        return ApiResponse::success(data: [
            'meeting_title' => $latestEvent->title,
            'meeting_date'  => $latestEvent->starts_at,
            'meeting_id'    => $latestEvent->id,
            'tasks'         => MeetingTaskResource::collection($tasks),
            'other_tasks'   => MeetingTaskResource::collection($otherTasks),
        ]);
    }
}
