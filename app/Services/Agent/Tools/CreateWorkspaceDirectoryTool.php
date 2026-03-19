<?php

namespace App\Services\Agent\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAccessService;
use App\Services\Workspace\WorkspaceService;

class CreateWorkspaceDirectoryTool extends AbstractAgentTool
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
        return 'create_workspace_directory';
    }

    public function getDescription(): string
    {
        return 'Create a directory inside a writable workspace.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'required' => ['workspace_id', 'path'],
            'properties' => [
                'workspace_id' => ['type' => 'string', 'description' => 'Workspace UUID from list_workspaces.'],
                'path' => ['type' => 'string', 'description' => 'Relative directory path to create.'],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $workspace = $this->resolveWorkspace((string) ($parameters['workspace_id'] ?? ''), 'write');
        if ($workspace === null) {
            return ['success' => false, 'error' => 'Workspace not found or access denied'];
        }

        $path = (string) ($parameters['path'] ?? '');

        return [
            'success' => $this->workspaceService->makeDirectory($workspace, $path),
            'workspace_id' => $workspace->id,
            'path' => $path,
        ];
    }

    private function resolveWorkspace(string $workspaceId, string $ability): ?Workspace
    {
        $workspace = Workspace::find($workspaceId);
        if (! $workspace) {
            return null;
        }

        return $this->workspaceAccessService->can($this->user, $workspace, $ability, $this->organizationId, $this->teamId) ? $workspace : null;
    }
}
