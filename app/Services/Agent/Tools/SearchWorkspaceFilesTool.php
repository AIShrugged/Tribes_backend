<?php

namespace App\Services\Agent\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAccessService;
use App\Services\Workspace\WorkspaceService;

class SearchWorkspaceFilesTool extends AbstractAgentTool
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
        return 'search_workspace_files';
    }

    public function getDescription(): string
    {
        return 'Search files by relative path/name inside a readable workspace.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'required' => ['workspace_id', 'query'],
            'properties' => [
                'workspace_id' => [
                    'type' => 'string',
                    'description' => 'Workspace UUID from list_workspaces.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Case-insensitive substring to search within file paths.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Optional relative directory to constrain the search.',
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

        return [
            'success' => true,
            'workspace_id' => $workspace->id,
            'results' => $this->workspaceService->searchFiles(
                $workspace,
                (string) ($parameters['query'] ?? ''),
                (string) ($parameters['path'] ?? ''),
            ),
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
