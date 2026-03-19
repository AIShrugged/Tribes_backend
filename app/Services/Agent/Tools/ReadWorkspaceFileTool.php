<?php

namespace App\Services\Agent\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAccessService;
use App\Services\Workspace\WorkspaceService;

class ReadWorkspaceFileTool extends AbstractAgentTool
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
        return 'read_workspace_file';
    }

    public function getDescription(): string
    {
        return 'Read a file from a user-accessible workspace.';
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
                    'description' => 'Relative path to the file inside the workspace.',
                ],
                'max_bytes' => [
                    'type' => 'integer',
                    'description' => 'Optional maximum number of bytes to return. Large files are truncated with metadata.',
                ],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $workspace = $this->resolveWorkspace((string) ($parameters['workspace_id'] ?? ''), 'read');
        if ($workspace === null) {
            return ['success' => false, 'error' => 'Workspace not found or access denied'];
        }

        $path = (string) ($parameters['path'] ?? '');

        return [
            'success' => true,
            'workspace_id' => $workspace->id,
            ...$this->workspaceService->readFileWithLimit(
                $workspace,
                $path,
                isset($parameters['max_bytes']) ? (int) $parameters['max_bytes'] : null,
            ),
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
