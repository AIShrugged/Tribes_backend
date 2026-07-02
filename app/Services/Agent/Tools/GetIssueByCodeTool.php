<?php

namespace App\Services\Agent\Tools;

use App\Models\Issue;
use App\Models\User;
use App\Services\Agent\Tools\Concerns\ResolvesIssueTenantScope;

class GetIssueByCodeTool extends AbstractAgentTool
{
    use ResolvesIssueTenantScope;

    public function __construct(
        private readonly ?User $user = null,
        private readonly ?int $organizationId = null,
        private readonly ?int $teamId = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'get_issue_by_code';
    }

    public function getDescription(): string
    {
        return 'Resolve a tracker issue by its human code (e.g. "DEV-14") — the identifier users see and type. '
            .'Use this whenever the user references a task by its code. Case-insensitive and org-scoped: a code '
            .'outside the current scope returns not found. Returns the issue id, name, status, assignee and due date.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code' => ['type' => 'string', 'description' => 'The issue code, e.g. "DEV-14" (case-insensitive).'],
                'organization_id' => ['type' => 'integer', 'description' => 'Defaults to the run organization.'],
                'team_id' => ['type' => 'integer', 'description' => 'Optional team scope.'],
            ],
            'required' => ['code'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];
        $code = strtoupper(trim((string) ($parameters['code'] ?? '')));
        if ($code === '') {
            return ['success' => false, 'error' => 'code is required'];
        }

        $scope = $this->resolveTenantScope($this->user, $this->organizationId, $this->teamId, $parameters['organization_id'] ?? null, $parameters['team_id'] ?? null);
        if ($scope['success'] === false) {
            return $scope;
        }
        $orgId = $scope['organization_id'];

        $issue = Issue::query()->withoutTrashed()
            ->when($orgId, fn ($q) => $q->inOrganization($orgId))
            ->where('code', $code)
            ->first();

        if (! $issue) {
            // Identical message for cross-org and non-existent — no probing.
            return ['success' => false, 'error' => 'Issue not found or not in scope.'];
        }

        return [
            'success' => true,
            'id' => $issue->id,
            'code' => $issue->code,
            'number' => $issue->number,
            'name' => $issue->name,
            'status' => $issue->status,
            'assignee_name' => $issue->assignee?->name ?? $issue->assignee_name,
            'assignee_id' => $issue->assignee_id,
            'due_date' => $issue->due_date?->toDateString(),
            'organization_id' => $issue->organization_id,
            'team_id' => $issue->team_id,
        ];
    }
}
