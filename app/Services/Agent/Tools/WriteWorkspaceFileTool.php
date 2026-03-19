<?php

namespace App\Services\Agent\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAccessService;
use App\Services\Workspace\WorkspaceService;

class WriteWorkspaceFileTool extends AbstractAgentTool
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
        return 'write_workspace_file';
    }

    public function getDescription(): string
    {
        return 'Create or overwrite a file inside a workspace that the current user can write to.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'required' => ['workspace_id', 'path', 'contents'],
            'properties' => [
                'workspace_id' => [
                    'type' => 'string',
                    'description' => 'Workspace UUID from list_workspaces.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Relative file path inside the workspace.',
                ],
                'contents' => [
                    'type' => 'string',
                    'description' => 'Full file contents to write.',
                ],
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
        $this->workspaceService->writeFile($workspace, $path, (string) ($parameters['contents'] ?? ''));

        return [
            'success' => true,
            'workspace_id' => $workspace->id,
            'path' => $path,
            'message' => 'Workspace file written successfully',
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
