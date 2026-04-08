<?php

namespace App\Services\Agent\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceService;

class DeleteWorkspaceTool extends AbstractAgentTool
{
    public function __construct(
        private readonly User $user,
        private readonly WorkspaceService $workspaceService,
        private readonly ?int $organizationId = null,
        private readonly ?int $teamId = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'delete_workspace';
    }

    public function getDescription(): string
    {
        return 'Delete a workspace. Allowed for workspaces owned by the current user, or for any workspace inside the current organization when the user is an organization manager.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'required' => ['workspace_id'],
            'properties' => [
                'workspace_id' => [
                    'type' => 'string',
                    'description' => 'Workspace UUID to delete.',
                ],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $workspace = Workspace::find((string) ($parameters['workspace_id'] ?? ''));
        if (! $workspace) {
            return ['success' => false, 'error' => 'Workspace not found or access denied'];
        }

        if (! $this->workspaceMatchesScope($workspace)) {
            return ['success' => false, 'error' => 'Workspace not found or access denied'];
        }

        $isOwner = (int) ($workspace->owner_user_id ?? 0) === (int) $this->user->id;
        $isMember = $this->user->isOrganizationMember($workspace->organization_id);

        if (! $isOwner && ! $isMember) {
            return ['success' => false, 'error' => 'Workspace not found or access denied'];
        }

        return [
            'success' => true,
            'workspace_id' => $workspace->id,
            'deleted' => $this->workspaceService->deleteWorkspace($workspace),
        ];
    }

    private function workspaceMatchesScope(Workspace $workspace): bool
    {
        if ($this->organizationId !== null && (int) $workspace->organization_id !== $this->organizationId) {
            return false;
        }

        if ($this->teamId === null) {
            return true;
        }

        if ((int) ($workspace->team_id ?? 0) === $this->teamId) {
            return true;
        }

        return in_array($workspace->scope_type, ['org_shared', 'personal_shared'], true) && $workspace->team_id === null;
    }
}
