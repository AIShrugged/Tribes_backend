<?php

namespace App\Services\Agent\Tools;

use App\Models\Issue;
use App\Models\User;
use App\Services\Agent\Tools\Concerns\ResolvesIssueTenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class GetIssueCandidatesTool extends AbstractAgentTool
{
    use ResolvesIssueTenantScope;

    private const DEFAULT_RECENT_DAYS = 14;

    public function __construct(
        private readonly ?User $user = null,
        private readonly ?int $organizationId = null,
        private readonly ?int $teamId = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'get_issue_candidates';
    }

    public function getDescription(): string
    {
        return 'List candidate tracker issues to match git commits against: open/in-progress tasks PLUS tasks closed within the recent window (default 14 days). Lightweight (id, name, status — NO description; use get_issue_detail for the full spec). Org-scoped. Match a commit to AT MOST ONE issue, and prefer leaving it unmatched over a weak guess.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'organization_id' => ['type' => 'integer', 'description' => 'Org to scope to. Defaults to the run organization.'],
                'team_id' => ['type' => 'integer', 'description' => 'Optional team filter.'],
                'development_only' => ['type' => 'boolean', 'description' => 'Only development-type issues (default true). Set false to include organization/epic tasks.'],
                'recently_closed_days' => ['type' => 'integer', 'description' => 'How many days back a closed task still counts as a candidate (default 14).'],
                'limit' => ['type' => 'integer', 'description' => 'Max issues (default 50, max 150).'],
            ],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];
        $scope = $this->resolveTenantScope($this->user, $this->organizationId, $this->teamId, $parameters['organization_id'] ?? null, $parameters['team_id'] ?? null);
        if ($scope['success'] === false) {
            return $scope;
        }
        $orgId = $scope['organization_id'];
        $teamId = $scope['team_id'];

        $developmentOnly = ($parameters['development_only'] ?? true) !== false;
        $recentDays = max(0, (int) ($parameters['recently_closed_days'] ?? self::DEFAULT_RECENT_DAYS));
        $limit = min(max(1, (int) ($parameters['limit'] ?? 50)), 150);
        $cutoff = Carbon::now()->subDays($recentDays);

        $query = Issue::query()->withoutTrashed();

        if ($orgId) {
            $query->inOrganization($orgId);
        }
        if ($teamId) {
            $query->where('team_id', $teamId);
        }

        // Open-ish OR recently closed (close_date is stamped when status -> done/closed/cancelled).
        $query->where(function (Builder $q) use ($cutoff) {
            $q->whereNotIn('status', ['done', 'closed', 'cancelled'])
                ->orWhere('close_date', '>=', $cutoff);
        });

        if ($developmentOnly) {
            $this->applyDevelopmentScope($query);
        }

        $now = Carbon::now();
        $issues = $query->orderByRaw('updated_at DESC NULLS LAST')->limit($limit)->get(['id', 'name', 'status', 'close_date', 'updated_at']);

        return [
            'success' => true,
            'organization_id' => $orgId,
            'count' => $issues->count(),
            'issues' => $issues->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->name,
                'status' => $i->status,
                'closed_at' => $i->close_date?->toDateString(),
                'days_since_update' => $i->updated_at ? (int) abs($now->diffInDays($i->updated_at)) : null,
            ])->all(),
        ];
    }

    /** Mirror Issue::isDevelopment() precedence: issueType.base_type wins; legacy type only when base_type IS NULL. */
    private function applyDevelopmentScope(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->whereHas('issueType', fn (Builder $it) => $it->where('base_type', 'development'))
                ->orWhere(function (Builder $q2) {
                    $q2->where(function (Builder $noBase) {
                        $noBase->whereDoesntHave('issueType')
                            ->orWhereHas('issueType', fn (Builder $it) => $it->whereNull('base_type'));
                    })->whereIn('type', [Issue::TYPE_DEVELOPMENT, Issue::TYPE_FRONTEND, Issue::TYPE_BACKEND]);
                });
        });
    }
}
