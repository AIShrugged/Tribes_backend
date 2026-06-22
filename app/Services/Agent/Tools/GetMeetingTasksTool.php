<?php

namespace App\Services\Agent\Tools;

use App\Models\CalendarEvent;
use App\Models\Participant;
use App\Models\Issue;
use App\Models\Team;
use App\Models\User;
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
    use \App\Services\Agent\Tools\Concerns\InteractsWithMcpTenant;

    public function __construct(
        private readonly ?User $user = null,
        private readonly ?int $organizationId = null,
        private readonly ?int $teamId = null,
    ) {
        parent::__construct();
    }

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
                'team_id' => [
                    'type'        => 'integer',
                    'description' => 'Required unless organization_id is provided: filter tasks belonging to a specific team.',
                ],
                'organization_id' => [
                    'type'        => 'integer',
                    'description' => 'Required unless team_id is provided: filter tasks belonging to a specific organization.',
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
                'created_before' => [
                    'type'        => 'string',
                    'description' => 'Optional: filter tasks created on or before this date (YYYY-MM-DD).',
                ],
                'created_after' => [
                    'type'        => 'string',
                    'description' => 'Optional: filter tasks created on or after this date (YYYY-MM-DD).',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $eventId      = $parameters['calendar_event_id'] ?? null;
        $teamId       = $parameters['team_id'] ?? null;
        $orgId        = $parameters['organization_id'] ?? null;
        $assigneeId   = $parameters['assignee_id'] ?? null;
        $assigneeName = $parameters['assignee_name'] ?? null;
        $status       = $parameters['status'] ?? null;
        $dueBefore    = $parameters['due_before'] ?? null;
        $dueAfter     = $parameters['due_after'] ?? null;
        $createdBefore = $parameters['created_before'] ?? null;
        $createdAfter  = $parameters['created_after'] ?? null;

        $scope = $this->resolveTenantScope($orgId, $teamId);
        if ($scope['success'] === false) {
            return $scope;
        }

        $orgId = $scope['organization_id'];
        $teamId = $scope['team_id'];

        $query = Issue::query()->withoutTrashed();

        if ($eventId) {
            $query->where('sourceable_type', CalendarEvent::class)
                ->where('sourceable_id', $eventId);
        }

        if ($teamId) {
            $query->where('team_id', $teamId);
        }

        if ($orgId) {
            $query->inOrganization($orgId);
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

        if ($createdBefore) {
            $query->where('created_at', '<=', $this->parseDateBoundary($createdBefore, endOfDay: true));
        }

        if ($createdAfter) {
            $query->where('created_at', '>=', $this->parseDateBoundary($createdAfter, endOfDay: false));
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
                'created_at'    => $task->created_at?->toDateString(),
                'status'        => $task->status,
                'team_id'       => $task->team_id,
                'organization_id' => $task->organization_id,
                'sourceable_type' => $task->sourceable_type,
                'sourceable_id'   => $task->sourceable_id,
            ])->toArray(),
        ];
    }

    private function parseDateBoundary(string $value, bool $endOfDay): Carbon
    {
        $date = Carbon::parse($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1) {
            return $endOfDay ? $date->endOfDay() : $date->startOfDay();
        }

        return $date;
    }

    private function resolveTenantScope(mixed $orgId, mixed $teamId): array
    {
        // Resolve the acting user: a real injected user (internal agent path) or
        // the authenticated Sanctum user (MCP path). Null only when invoked with
        // no user context at all (internal/system callers) — membership checks
        // are then skipped, preserving prior behaviour; the MCP route always has
        // an authenticated user so it is always enforced.
        $user = $this->currentUser($this->user);

        $orgId = $orgId !== null && $orgId !== '' ? (int) $orgId : $this->organizationId;
        $teamId = $teamId !== null && $teamId !== '' ? (int) $teamId : $this->teamId;

        // Default to the acting user's organization when nothing was provided
        // (e.g. an MCP service user scoped to a single org).
        if ($orgId === null && $teamId === null && $user !== null) {
            $orgId = $this->currentOrganizationId($user);
        }

        if ($orgId === null && $teamId === null) {
            return [
                'success' => false,
                'error' => 'organization_id or team_id is required for task queries.',
            ];
        }

        if ($teamId !== null) {
            $team = Team::query()->find($teamId);
            if (! $team) {
                return ['success' => false, 'error' => "Team {$teamId} not found."];
            }

            if ($orgId !== null && (int) $team->organization_id !== $orgId) {
                return ['success' => false, 'error' => 'team_id does not belong to organization_id.'];
            }

            $orgId ??= (int) $team->organization_id;

            if ($user !== null && ! $user->isTeamMember($team)) {
                return ['success' => false, 'error' => 'You do not have access to this team.'];
            }
        }

        if ($orgId !== null && $user !== null && ! $user->isOrganizationMember($orgId)) {
            return ['success' => false, 'error' => 'You do not have access to this organization.'];
        }

        return [
            'success' => true,
            'organization_id' => $orgId,
            'team_id' => $teamId,
        ];
    }
}
