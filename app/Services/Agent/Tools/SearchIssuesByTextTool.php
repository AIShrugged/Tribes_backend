<?php

namespace App\Services\Agent\Tools;

use App\Models\Issue;
use App\Models\User;
use App\Services\Agent\Support\AgentRunToolBudget;
use App\Services\Agent\Tools\Concerns\ResolvesIssueTenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class SearchIssuesByTextTool extends AbstractAgentTool
{
    use ResolvesIssueTenantScope;

    public function __construct(
        private readonly ?User $user = null,
        private readonly ?int $organizationId = null,
        private readonly ?int $teamId = null,
        private readonly ?int $agentTaskRunId = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'search_issues_by_text';
    }

    public function getDescription(): string
    {
        return 'Rank tracker issues by how well they match a commit text (branch / message / keywords). Full-text search over issue name+description, with a name-substring fallback. Org-scoped to open + recently-closed tasks. Budget-limited per run — use it only for commits you cannot match from get_issue_candidates alone.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Text to match (commit message / branch slug / a few keywords).'],
                'organization_id' => ['type' => 'integer', 'description' => 'Defaults to the run organization.'],
                'team_id' => ['type' => 'integer', 'description' => 'Optional team filter.'],
                'limit' => ['type' => 'integer', 'description' => 'Max ranked issues (default 10, max 25).'],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $budget = (int) config('agent.commit_report.match.search_budget_per_run', 8);
        if (! AgentRunToolBudget::consume($this->agentTaskRunId, 'search_issues', $budget)) {
            return [
                'success' => true,
                'budget_exhausted' => true,
                'issues' => [],
                'message' => 'Search budget for this run is spent. Rely on get_issue_candidates, or leave the commit unmatched.',
            ];
        }

        $query = trim((string) ($parameters['query'] ?? ''));
        if ($query === '') {
            return ['success' => false, 'error' => 'query is required'];
        }

        $scope = $this->resolveTenantScope($this->user, $this->organizationId, $this->teamId, $parameters['organization_id'] ?? null, $parameters['team_id'] ?? null);
        if ($scope['success'] === false) {
            return $scope;
        }
        $orgId = $scope['organization_id'];
        $teamId = $scope['team_id'];
        $limit = min(max(1, (int) ($parameters['limit'] ?? 10)), 25);
        $cutoff = Carbon::now()->subDays(14);

        $base = fn () => Issue::query()->withoutTrashed()
            ->when($orgId, fn (Builder $q) => $q->inOrganization($orgId))
            ->when($teamId, fn (Builder $q) => $q->where('team_id', $teamId))
            ->where(function (Builder $q) use ($cutoff) {
                $q->whereNotIn('status', ['done', 'closed', 'cancelled'])->orWhere('close_date', '>=', $cutoff);
            });

        $tsv = "to_tsvector('russian', coalesce(name, '') || ' ' || coalesce(description, ''))";

        // Bindings bind in clause order: WHERE then ORDER BY — one ? in each, same query text.
        $matched = $base()
            ->whereRaw("{$tsv} @@ plainto_tsquery('russian', ?)", [$query])
            ->orderByRaw("ts_rank({$tsv}, plainto_tsquery('russian', ?)) DESC", [$query])
            ->limit($limit)
            ->get(['id', 'name', 'status', 'close_date']);

        $source = 'fts';
        if ($matched->isEmpty()) {
            $matched = $base()
                ->where('name', 'ilike', '%'.$query.'%')
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->get(['id', 'name', 'status', 'close_date']);
            $source = 'ilike';
        }

        return [
            'success' => true,
            'source' => $source,
            'count' => $matched->count(),
            'issues' => $matched->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->name,
                'status' => $i->status,
                'closed_at' => $i->close_date?->toDateString(),
            ])->all(),
        ];
    }
}
