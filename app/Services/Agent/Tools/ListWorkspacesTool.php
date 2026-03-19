<?php

namespace App\Services\Agent\Tools;

use App\Models\User;
use App\Services\Workspace\WorkspaceAccessService;

class ListWorkspacesTool extends AbstractAgentTool
{
    public function __construct(
        private readonly User $user,
        private readonly WorkspaceAccessService $workspaceAccessService,
        private readonly ?int $organizationId = null,
        private readonly ?int $teamId = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'list_workspaces';
    }

    public function getDescription(): string
    {
        return 'List all workspaces available to the current user, including scope, ids, and effective permissions. Use this first before reading or writing workspace files.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        return [
            'success' => true,
            'workspaces' => $this->workspaceAccessService->manifestForUser(
                $this->user,
                null,
                $this->organizationId,
                $this->teamId,
            )->all(),
        ];
    }
}
