<?php

namespace App\Services\Agent\Tools;

use App\Models\Issue;
use App\Models\User;
use App\Services\Agent\Tools\Concerns\ResolvesIssueTenantScope;

class GetIssueDetailTool extends AbstractAgentTool
{
    use ResolvesIssueTenantScope;

    private const MAX_DESCRIPTION_CHARS = 11000;

    public function __construct(
        private readonly ?User $user = null,
        private readonly ?int $organizationId = null,
        private readonly ?int $teamId = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'get_issue_detail';
    }

    public function getDescription(): string
    {
        return 'Get the FULL specification (description = TZ) of one tracker issue by id, to judge whether a commit covers it. Org-scoped. The description is capped so the result stays within tool limits; has_description=false means there is no spec to check against (then say spec coverage is unknown).';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'issue_id' => ['type' => 'integer', 'description' => 'The issue id.'],
                'organization_id' => ['type' => 'integer', 'description' => 'Defaults to the run organization.'],
                'team_id' => ['type' => 'integer', 'description' => 'Optional team scope.'],
            ],
            'required' => ['issue_id'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];
        $issueId = (int) ($parameters['issue_id'] ?? 0);
        if ($issueId <= 0) {
            return ['success' => false, 'error' => 'issue_id is required'];
        }

        $scope = $this->resolveTenantScope($this->user, $this->organizationId, $this->teamId, $parameters['organization_id'] ?? null, $parameters['team_id'] ?? null);
        if ($scope['success'] === false) {
            return $scope;
        }
        $orgId = $scope['organization_id'];

        $issue = Issue::query()->withoutTrashed()
            ->when($orgId, fn ($q) => $q->inOrganization($orgId))
            ->whereKey($issueId)
            ->first();

        if (! $issue) {
            // Identical message for cross-org and non-existent — no probing.
            return ['success' => false, 'error' => 'Issue not found or not in scope.'];
        }

        $description = (string) ($issue->description ?? '');
        $truncated = mb_strlen($description) > self::MAX_DESCRIPTION_CHARS;

        return [
            'success' => true,
            'id' => $issue->id,
            'name' => $issue->name,
            'status' => $issue->status,
            'closed_at' => $issue->close_date?->toDateString(),
            'due_date' => $issue->due_date?->toDateString(),
            'assignee_name' => $issue->assignee_name,
            'has_description' => trim($description) !== '',
            'description_truncated' => $truncated,
            'description' => $truncated
                ? mb_substr($description, 0, self::MAX_DESCRIPTION_CHARS)."\n...[description truncated]"
                : $description,
        ];
    }
}
