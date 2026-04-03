<?php

namespace App\Services\Agent\Tools;

use App\Models\CalendarEvent;
use App\Models\Participant;
use App\Models\Issue;
use Illuminate\Support\Carbon;

/**
 * Retrieves tasks and action items, optionally filtered by meeting, assignee, or status.
 *
 * Example questions this tool answers:
 * - "Какие задачи были поставлены на встрече в пятницу?"
 * - "What action items came out of the sprint review?"
 * - "Что задано Ивану по итогам встречи?"
 * - "Покажи все незакрытые задачи."
 * - "Are there any overdue tasks from the planning session?"
 * - "Who was assigned the most tasks in this meeting?"
 */
class GetMeetingTasksTool extends AbstractAgentTool
{
    public function getName(): string
    {
        return 'get_tasks';
    }

    public function getDescription(): string
    {
        return 'Get tasks and action items. Can filter by meeting (calendar_event_id), assignee (assignee_id), or status. If no filters are provided, returns all tasks. Use ONLY when the user explicitly asks about tasks, action items, or assignments. Do NOT call this tool when the user asks what was discussed or what happened at a meeting — use get_meeting_summary instead.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'calendar_event_id' => [
                    'type'        => 'integer',
                    'description' => 'Optional: filter tasks linked to a specific calendar event (meeting) by its ID.',
                ],
                'assignee_id' => [
                    'type'        => 'integer',
                    'description' => 'Optional: filter tasks assigned to a specific person by their user ID.',
                ],
                'assignee_name' => [
                    'type'        => 'string',
                    'description' => 'Optional: filter tasks by assignee name (case-insensitive, partial match). Use when you have a name but no assignee_id.',
                ],
                'status' => [
                    'type'        => 'string',
                    'description' => 'Optional: filter tasks by status. Common values: open, in_progress, paused, done.',
                ],
                'due_before' => [
                    'type'        => 'string',
                    'description' => 'Optional: filter tasks with due_date on or before this date (YYYY-MM-DD).',
                ],
                'due_after' => [
                    'type'        => 'string',
                    'description' => 'Optional: filter tasks with due_date on or after this date (YYYY-MM-DD).',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $eventId      = $parameters['calendar_event_id'] ?? null;
        $assigneeId   = $parameters['assignee_id'] ?? null;
        $assigneeName = $parameters['assignee_name'] ?? null;
        $status       = $parameters['status'] ?? null;
        $dueBefore    = $parameters['due_before'] ?? null;
        $dueAfter     = $parameters['due_after'] ?? null;

        $query = Issue::query()->withoutTrashed();

        if ($eventId) {
            $query->where('sourceable_type', CalendarEvent::class)
                ->where('sourceable_id', $eventId);
        }

        if ($assigneeName) {
            $query->where('assignee_name', 'ilike', '%' . $assigneeName . '%');
        }

        if ($assigneeId) {
            $participantQuery = Participant::whereHas('profile', fn ($q) => $q->where('user_id', $assigneeId));
            if ($eventId) {
                $participantQuery->where('calendar_event_id', $eventId);
            }
            $assigneeNames = $participantQuery->pluck('name')->unique()->values()->toArray();

            $query->where(function ($q) use ($assigneeId, $assigneeNames) {
                $q->where('assignee_id', $assigneeId);
                if (!empty($assigneeNames)) {
                    $q->orWhereIn('assignee_name', $assigneeNames);
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
                'success'     => true,
                'tasks_count' => 0,
                'message'     => 'No tasks found'
                    . ($eventId ? " for meeting #{$eventId}" : '')
                    . ($status ? " with status '{$status}'" : '')
                    . '.',
            ];
        }

        return [
            'success'     => true,
            'tasks_count' => $tasks->count(),
            'tasks'       => $tasks->map(fn ($task) => [
                'id'            => $task->id,
                'name'          => $task->name,
                'description'   => $task->description,
                'assignee_name' => $task->assignee_name,
                'assignee_id'   => $task->assignee_id,
                'due_date'      => $task->due_date?->toDateString(),
                'status'        => $task->status,
                'sourceable_type' => $task->sourceable_type,
                'sourceable_id'   => $task->sourceable_id,
            ])->toArray(),
        ];
    }
}
