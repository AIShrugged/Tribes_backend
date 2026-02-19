<?php

namespace App\Services\Agent\Tools;

use App\Models\MeetingTask;
use App\Models\Participant;
use Illuminate\Support\Carbon;

/**
 * Retrieves action items and tasks assigned during a meeting.
 *
 * Example questions this tool answers:
 * - "Какие задачи были поставлены на встрече в пятницу?"
 * - "What action items came out of the sprint review?"
 * - "Что задано Ивану по итогам встречи?"
 * - "Покажи незакрытые задачи со встречи #42."
 * - "Are there any overdue tasks from the planning session?"
 * - "Who was assigned the most tasks in this meeting?"
 */
class GetMeetingTasksTool implements ToolInterface
{
    public function getName(): string
    {
        return 'get_meeting_tasks';
    }

    public function getDescription(): string
    {
        return 'Get action items and tasks created from a specific meeting. Returns task titles, descriptions, assignee names, due dates, and statuses. Use ONLY when the user explicitly asks about tasks, action items, or assignments. Do NOT call this tool when the user asks what was discussed or what happened at a meeting — use get_meeting_summary instead. Can filter by assignee (profile_id) or status.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'calendar_event_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the calendar event (meeting) to get tasks for.',
                ],
                'profile_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: filter tasks assigned to a specific person by their profile_id.',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Optional: filter tasks by status. Common values: open, in_progress, done, cancelled.',
                ],
                'due_before' => [
                    'type' => 'string',
                    'description' => 'Optional: filter tasks with due_date on or before this date (YYYY-MM-DD).',
                ],
                'due_after' => [
                    'type' => 'string',
                    'description' => 'Optional: filter tasks with due_date on or after this date (YYYY-MM-DD).',
                ],
            ],
            'required' => ['calendar_event_id'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $eventId   = $parameters['calendar_event_id'] ?? null;
        $profileId = $parameters['profile_id'] ?? null;
        $status    = $parameters['status'] ?? null;
        $dueBefore = $parameters['due_before'] ?? null;
        $dueAfter  = $parameters['due_after'] ?? null;

        if (! $eventId) {
            return [
                'success' => false,
                'error'   => 'calendar_event_id is required',
            ];
        }

        $query = MeetingTask::where('calendar_event_id', $eventId);

        if ($profileId) {
            // Try filtering by profile_id first; if tasks aren't linked, fall back to assignee_name
            $participant = Participant::where('calendar_event_id', $eventId)
                ->where('profile_id', $profileId)
                ->first();

            $query->where(function ($q) use ($profileId, $participant) {
                $q->where('profile_id', $profileId);
                if ($participant) {
                    $q->orWhere('assignee_name', $participant->name);
                }
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($dueBefore) {
            $query->where('due_date', '<=', Carbon::parse($dueBefore)->toDateString());
        }

        if ($dueAfter) {
            $query->where('due_date', '>=', Carbon::parse($dueAfter)->toDateString());
        }

        $tasks = $query->orderByRaw('due_date ASC NULLS LAST')->get();

        if ($tasks->isEmpty()) {
            return [
                'success'           => true,
                'calendar_event_id' => $eventId,
                'tasks_count'       => 0,
                'message'           => 'No tasks found for this meeting'
                    . ($status ? " with status '{$status}'" : '')
                    . '.',
            ];
        }

        return [
            'success'           => true,
            'calendar_event_id' => $eventId,
            'tasks_count'       => $tasks->count(),
            'tasks'             => $tasks->map(fn ($task) => [
                'id'            => $task->id,
                'title'         => $task->title,
                'description'   => $task->description,
                'assignee_name' => $task->assignee_name,
                'profile_id'    => $task->profile_id,
                'due_date'      => $task->due_date?->toDateString(),
                'status'        => $task->status,
            ])->toArray(),
        ];
    }
}
