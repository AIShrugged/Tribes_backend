<?php

namespace App\Services\Agent\Tools;

use App\Models\Issue;
use Illuminate\Support\Carbon;

/**
 * Retrieves open issues from the task tracker, optionally filtered by team, assignee, or staleness.
 *
 * Example questions this tool answers:
 * - "Какие задачи висят у Ивана уже неделю?"
 * - "Есть ли незакрытые задачи в команде перед митингом?"
 * - "Покажи всё что не сделано в команде Backenders"
 * - "У кого есть задачи просроченные больше 7 дней?"
 */
class GetOpenIssuesTool extends AbstractAgentTool
{
    public function getName(): string
    {
        return 'get_open_issues';
    }

    public function getDescription(): string
    {
        return 'Get open/in-progress issues from the task tracker. Can filter by team, assignee name, or staleness (days without update). Returns days_since_update for each task so you can identify stale ones. Use this to monitor task completion before meetings or to find overdue work.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'team_id' => [
                    'type'        => 'integer',
                    'description' => 'Optional: filter issues belonging to a specific team.',
                ],
                'organization_id' => [
                    'type'        => 'integer',
                    'description' => 'Optional: filter issues belonging to a specific organization.',
                ],
                'assignee_name' => [
                    'type'        => 'string',
                    'description' => 'Optional: filter by assignee name (case-insensitive, partial match).',
                ],
                'assignee_id' => [
                    'type'        => 'integer',
                    'description' => 'Optional: filter by assignee user ID.',
                ],
                'stale_days' => [
                    'type'        => 'integer',
                    'description' => 'Optional: only return issues not updated for at least this many days. E.g. 7 means "stale for a week".',
                ],
                'statuses' => [
                    'type'        => 'string',
                    'description' => 'Optional: comma-separated statuses to include. Defaults to "open,in_progress". Example: "open,in_progress,paused".',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Optional: max number of issues to return. Defaults to 50.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters   = $parameters ?? [];
        $teamId       = $parameters['team_id'] ?? null;
        $orgId        = $parameters['organization_id'] ?? null;
        $assigneeName = $parameters['assignee_name'] ?? null;
        $assigneeId   = $parameters['assignee_id'] ?? null;
        $staleDays    = $parameters['stale_days'] ?? null;
        $limit        = min((int) ($parameters['limit'] ?? 50), 200);

        $statusesRaw = $parameters['statuses'] ?? 'open,in_progress';
        $statuses    = array_filter(array_map('trim', explode(',', $statusesRaw)));

        $query = Issue::query()->withoutTrashed();

        $query->whereIn('status', $statuses);

        if ($teamId) {
            $query->where('team_id', $teamId);
        }

        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        if ($assigneeName) {
            $query->where('assignee_name', 'ilike', '%' . $assigneeName . '%');
        }

        if ($assigneeId) {
            $query->where('assignee_id', $assigneeId);
        }

        if ($staleDays !== null && $staleDays > 0) {
            $cutoff = Carbon::now()->subDays($staleDays);
            $query->where('updated_at', '<=', $cutoff);
        }

        $issues = $query
            ->orderByRaw('updated_at ASC NULLS LAST')
            ->limit($limit)
            ->get();

        if ($issues->isEmpty()) {
            return [
                'success'      => true,
                'issues_count' => 0,
                'message'      => 'No open issues found matching the filters.',
            ];
        }

        $now = Carbon::now();

        return [
            'success'      => true,
            'issues_count' => $issues->count(),
            'issues'       => $issues->map(fn ($issue) => [
                'id'                => $issue->id,
                'name'              => $issue->name,
                'description'       => $issue->description
                    ? mb_substr($issue->description, 0, 300)
                    : null,
                'status'            => $issue->status,
                'assignee_name'     => $issue->assignee_name,
                'assignee_id'       => $issue->assignee_id,
                'owner_user_id'     => $issue->user_id,
                'due_date'          => $issue->due_date?->toDateString(),
                'team_id'           => $issue->team_id,
                'organization_id'   => $issue->organization_id,
                'days_since_update' => (int) abs($now->diffInDays($issue->updated_at)),
                'registration_date' => $issue->registration_date?->toDateString(),
                'created_at'        => $issue->created_at->toDateString(),
            ])->toArray(),
        ];
    }
}
