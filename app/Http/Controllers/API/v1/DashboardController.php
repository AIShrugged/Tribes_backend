<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use App\Models\Followup;
use App\Models\MeetingSummary;
use App\Models\MeetingTask;
use App\Models\Source;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * @group Dashboard
 */
class DashboardController extends Controller
{
    /**
     * Get dashboard statistics
     *
     * Returns aggregated statistics for the authenticated user's dashboard,
     * including meetings, participants, tasks, follow-ups, summaries and teams.
     *
     * @authenticated
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": {
     *     "meetings": {
     *       "total": 42,
     *       "with_bot": 18,
     *       "total_duration_minutes": 1260,
     *       "average_duration_minutes": 30,
     *       "recent": [
     *         {
     *           "id": 10,
     *           "title": "Q1 Planning",
     *           "starts_at": "2026-03-01T10:00:00.000000Z",
     *           "ends_at": "2026-03-01T11:00:00.000000Z",
     *           "duration_minutes": 60,
     *           "participants_count": 5
     *         }
     *       ],
     *       "by_month": [
     *         {"month": "2026-02", "count": 12, "total_duration_minutes": 360}
     *       ]
     *     },
     *     "participants": {
     *       "total_unique": 24,
     *       "average_per_meeting": 3.5,
     *       "top": [
     *         {"name": "John Doe", "meetings_count": 8}
     *       ]
     *     },
     *     "tasks": {
     *       "total": 30,
     *       "by_status": {"open": 10, "in_progress": 5, "done": 14, "cancelled": 1},
     *       "overdue": 3
     *     },
     *     "followups": {
     *       "total": 20,
     *       "by_status": {"done": 14, "in_progress": 4, "failed": 2}
     *     },
     *     "summaries": {
     *       "total": 15
     *     },
     *     "teams": {
     *       "total": 3,
     *       "list": [
     *         {"id": 1, "name": "Core Team", "members_count": 8}
     *       ]
     *     }
     *   },
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function index(): ApiResponse
    {
        $userId = Auth::id();

        $sourceIds = Source::owned($userId)->pluck('id');

        $eventsQuery = CalendarEvent::whereIn('source_id', $sourceIds);

        // --- Meetings ---
        $totalMeetings = (clone $eventsQuery)->count();
        $withBot       = (clone $eventsQuery)->where('has_bot', true)->count();

        $durationData = (clone $eventsQuery)
            ->selectRaw('
                SUM(TIMESTAMPDIFF(MINUTE, starts_at, ends_at)) as total_minutes,
                AVG(TIMESTAMPDIFF(MINUTE, starts_at, ends_at)) as avg_minutes
            ')
            ->first();

        $totalDurationMinutes   = (int) ($durationData->total_minutes ?? 0);
        $averageDurationMinutes = (int) round($durationData->avg_minutes ?? 0);

        $recent = (clone $eventsQuery)
            ->select('id', 'title', 'starts_at', 'ends_at')
            ->selectRaw('TIMESTAMPDIFF(MINUTE, starts_at, ends_at) as duration_minutes')
            ->withCount('participants')
            ->orderByDesc('starts_at')
            ->limit(10)
            ->get()
            ->map(fn ($e) => [
                'id'                => $e->id,
                'title'             => $e->title,
                'starts_at'         => $e->starts_at,
                'ends_at'           => $e->ends_at,
                'duration_minutes'  => (int) $e->duration_minutes,
                'participants_count' => $e->participants_count,
            ]);

        $byMonth = (clone $eventsQuery)
            ->selectRaw("DATE_FORMAT(starts_at, '%Y-%m') as month")
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('SUM(TIMESTAMPDIFF(MINUTE, starts_at, ends_at)) as total_duration_minutes')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($r) => [
                'month'                  => $r->month,
                'count'                  => (int) $r->count,
                'total_duration_minutes' => (int) $r->total_duration_minutes,
            ]);

        // --- Participants ---
        $eventIds = (clone $eventsQuery)->pluck('id');

        $uniqueParticipants = DB::table('participants')
            ->whereIn('calendar_event_id', $eventIds)
            ->distinct()
            ->count('name');

        $averageParticipantsPerMeeting = $totalMeetings > 0
            ? round(
                DB::table('participants')->whereIn('calendar_event_id', $eventIds)->count() / $totalMeetings,
                1
            )
            : 0;

        $topParticipants = DB::table('participants')
            ->whereIn('calendar_event_id', $eventIds)
            ->select('name', DB::raw('COUNT(*) as meetings_count'))
            ->groupBy('name')
            ->orderByDesc('meetings_count')
            ->limit(10)
            ->get()
            ->map(fn ($p) => [
                'name'           => $p->name,
                'meetings_count' => (int) $p->meetings_count,
            ]);

        // --- Tasks ---
        $tasksQuery = MeetingTask::whereIn('calendar_event_id', $eventIds);
        $totalTasks = (clone $tasksQuery)->count();

        $tasksByStatus = (clone $tasksQuery)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $overdueTasksCount = (clone $tasksQuery)
            ->whereNotIn('status', ['done', 'cancelled'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now())
            ->count();

        // --- Followups ---
        $followupsQuery = Followup::owned($userId);
        $totalFollowups = (clone $followupsQuery)->count();

        $followupsByStatus = (clone $followupsQuery)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // --- Summaries ---
        $totalSummaries = MeetingSummary::whereIn('calendar_event_id', $eventIds)->count();

        // --- Teams ---
        $teams = Auth::user()
            ->teams()
            ->withCount('users')
            ->get()
            ->map(fn ($t) => [
                'id'            => $t->id,
                'name'          => $t->name,
                'members_count' => $t->users_count,
            ]);

        return ApiResponse::success(data: [
            'meetings' => [
                'total'                    => $totalMeetings,
                'with_bot'                 => $withBot,
                'total_duration_minutes'   => $totalDurationMinutes,
                'average_duration_minutes' => $averageDurationMinutes,
                'recent'                   => $recent,
                'by_month'                 => $byMonth,
            ],
            'participants' => [
                'total_unique'          => $uniqueParticipants,
                'average_per_meeting'   => $averageParticipantsPerMeeting,
                'top'                   => $topParticipants,
            ],
            'tasks' => [
                'total'     => $totalTasks,
                'by_status' => [
                    'open'        => (int) ($tasksByStatus['open'] ?? 0),
                    'in_progress' => (int) ($tasksByStatus['in_progress'] ?? 0),
                    'done'        => (int) ($tasksByStatus['done'] ?? 0),
                    'cancelled'   => (int) ($tasksByStatus['cancelled'] ?? 0),
                ],
                'overdue'   => $overdueTasksCount,
            ],
            'followups' => [
                'total'     => $totalFollowups,
                'by_status' => [
                    'done'        => (int) ($followupsByStatus['done'] ?? 0),
                    'in_progress' => (int) ($followupsByStatus['in_progress'] ?? 0),
                    'failed'      => (int) ($followupsByStatus['failed'] ?? 0),
                ],
            ],
            'summaries' => [
                'total' => $totalSummaries,
            ],
            'teams' => [
                'total' => $teams->count(),
                'list'  => $teams,
            ],
        ]);
    }
}