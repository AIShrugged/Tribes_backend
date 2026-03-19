<?php

namespace App\Services\Agent\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAccessService;
use App\Services\Workspace\WorkspaceService;

class CopyWorkspaceFileTool extends AbstractAgentTool
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
        return 'copy_workspace_file';
    }

    public function getDescription(): string
    {
        return 'Copy a file or directory within a writable workspace.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'required' => ['workspace_id', 'from_path', 'to_path'],
            'properties' => [
                'workspace_id' => ['type' => 'string', 'description' => 'Workspace UUID from list_workspaces.'],
                'from_path' => ['type' => 'string', 'description' => 'Source relative file path.'],
                'to_path' => ['type' => 'string', 'description' => 'Destination relative file path.'],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $workspace = $this->resolveWorkspace((string) ($parameters['workspace_id'] ?? ''), 'write');
        if ($workspace === null) {
            return ['success' => false, 'error' => 'Workspace not found or access denied'];
        }

        $fromPath = (string) ($parameters['from_path'] ?? '');
        $toPath = (string) ($parameters['to_path'] ?? '');

        return [
            'success' => $this->workspaceService->copy($workspace, $fromPath, $toPath),
            'workspace_id' => $workspace->id,
            'from_path' => $fromPath,
            'to_path' => $toPath,
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
