<?php

namespace App\Services\Agent\Tools;

use App\Models\Issue;
use App\Models\Team;
use App\Models\User;
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
                'created_before' => [
                    'type'        => 'string',
                    'description' => 'Optional: filter issues created on or before this date (YYYY-MM-DD).',
                ],
                'created_after' => [
                    'type'        => 'string',
                    'description' => 'Optional: filter issues created on or after this date (YYYY-MM-DD).',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Optional: max number of issues to return per page. Defaults to 50, max 200.',
                ],
                'offset' => [
                    'type'        => 'integer',
                    'description' => 'Optional: how many issues to skip (pagination). Use with the "total"/"has_more"/"next_offset" fields in the response to page through ALL results. Defaults to 0.',
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
        $createdBefore = $parameters['created_before'] ?? null;
        $createdAfter  = $parameters['created_after'] ?? null;
        $limit        = min((int) ($parameters['limit'] ?? 50), 200);
        $offset       = max(0, (int) ($parameters['offset'] ?? 0));

        $statusesRaw = $parameters['statuses'] ?? 'open,in_progress';
        $statuses    = array_filter(array_map('trim', explode(',', $statusesRaw)));

        $scope = $this->resolveTenantScope($orgId, $teamId);
        if ($scope['success'] === false) {
            return $scope;
        }

        $orgId = $scope['organization_id'];
        $teamId = $scope['team_id'];

        $query = Issue::query()->withoutTrashed();

        $query->whereIn('status', $statuses);

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
            $query->where('assignee_id', $assigneeId);
        }

        if ($staleDays !== null && $staleDays > 0) {
            $cutoff = Carbon::now()->subDays($staleDays);
            $query->where('updated_at', '<=', $cutoff);
        }

        if ($createdBefore) {
            $query->where('created_at', '<=', $this->parseDateBoundary($createdBefore, endOfDay: true));
        }

        if ($createdAfter) {
            $query->where('created_at', '>=', $this->parseDateBoundary($createdAfter, endOfDay: false));
        }

        $total = (clone $query)->count();

        $issues = $query
            ->orderByRaw('updated_at ASC NULLS LAST')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $hasMore = ($offset + $issues->count()) < $total;

        if ($issues->isEmpty()) {
            return [
                'success'      => true,
                'issues_count' => 0,
                'total'        => $total,
                'offset'       => $offset,
                'has_more'     => $hasMore,
                'next_offset'  => $hasMore ? $offset + $issues->count() : null,
                'message'      => $total > 0
                    ? 'No issues on this page; offset is past the end. Lower the offset.'
                    : 'No open issues found matching the filters.',
            ];
        }

        $now = Carbon::now();

        return [
            'success'      => true,
            'issues_count' => $issues->count(),
            'total'        => $total,
            'offset'       => $offset,
            'has_more'     => $hasMore,
            'next_offset'  => $hasMore ? $offset + $issues->count() : null,
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
