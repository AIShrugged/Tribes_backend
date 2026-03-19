<?php

namespace App\Services\Agent\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAccessService;
use App\Services\Workspace\WorkspaceService;

class DeleteWorkspaceFileTool extends AbstractAgentTool
{
    public function __construct(
        private readonly User $user,
        private readonly WorkspaceAccessService $workspaceAccessService,
        private readonly WorkspaceService $workspaceService,
        private readonly ?int $organizationId = null,
        private readonly ?int $teamId = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'delete_workspace_file';
    }

    public function getDescription(): string
    {
        return 'Delete a file or directory inside a workspace that the current user can modify.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'required' => ['workspace_id', 'path'],
            'properties' => [
                'workspace_id' => [
                    'type' => 'string',
                    'description' => 'Workspace UUID from list_workspaces.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Relative file path inside the workspace.',
                ],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $workspace = $this->resolveWorkspace((string) ($parameters['workspace_id'] ?? ''), 'delete');
        if ($workspace === null) {
            return ['success' => false, 'error' => 'Workspace not found or access denied'];
        }

        $path = (string) ($parameters['path'] ?? '');

        return [
            'success' => true,
            'workspace_id' => $workspace->id,
            'path' => $path,
            'deleted' => $this->workspaceService->delete($workspace, $path),
        ];
    }

    private function resolveWorkspace(string $workspaceId, string $ability): ?Workspace
    {
        $workspace = Workspace::find($workspaceId);
        if (! $workspace) {
            return null;
        }

        return $this->workspaceAccessService->abilitiesForUser($this->user, $workspace, $this->organizationId, $this->teamId)[$ability] ? $workspace : null;
    }
}
