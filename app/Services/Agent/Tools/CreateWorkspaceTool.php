<?php

namespace App\Services\Agent\Tools;

use App\Models\User;
use App\Services\Workspace\WorkspaceAccessService;
use App\Services\Workspace\WorkspaceProvisioningService;
use Illuminate\Validation\ValidationException;

class CreateWorkspaceTool extends AbstractAgentTool
{
    public function __construct(
        private readonly User $user,
        private readonly WorkspaceProvisioningService $workspaceProvisioningService,
        private readonly WorkspaceAccessService $workspaceAccessService,
        private readonly ?int $organizationId = null,
        private readonly ?int $teamId = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'create_workspace';
    }

    public function getDescription(): string
    {
        return 'Create a new workspace for the current user, team, or organization within the current tenant scope.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'required' => ['name', 'scope_type'],
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Human-readable workspace name.'],
                'scope_type' => [
                    'type' => 'string',
                    'enum' => ['org_shared', 'team_shared', 'user_private', 'user_team_private', 'personal_shared'],
                    'description' => 'Workspace scope. Shared scopes require manager permissions.',
                ],
                'organization_id' => ['type' => 'integer', 'description' => 'Organization id. Defaults to the current scoped organization when available.'],
                'team_id' => ['type' => 'integer', 'description' => 'Team id. Defaults to the current scoped team when available.'],
                'owner_user_id' => ['type' => 'integer', 'description' => 'Owner user id for private scopes. Defaults to the current user.'],
                'slug' => ['type' => 'string', 'description' => 'Optional custom slug.'],
                'metadata' => ['type' => 'string', 'description' => 'Optional metadata JSON object encoded as a string.'],
                'idempotency_key' => ['type' => 'string', 'description' => 'Optional idempotency key for safe retries.'],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        try {
            $attributes = $this->buildAttributes($parameters ?? []);
            $workspace = $this->workspaceProvisioningService->createWorkspace($this->user, $attributes);

            return [
                'success' => true,
                'workspace' => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'slug' => $workspace->slug,
                    'scope_type' => $workspace->scope_type,
                    'organization_id' => $workspace->organization_id,
                    'team_id' => $workspace->team_id,
                    'owner_user_id' => $workspace->owner_user_id,
                    'root_prefix' => $workspace->root_prefix,
                    'storage_disk' => $workspace->storage_disk,
                    'permissions' => $this->workspaceAccessService->abilitiesForUser(
                        $this->user,
                        $workspace,
                        $this->organizationId,
                        $this->teamId,
                    ),
                ],
            ];
        } catch (ValidationException $exception) {
            return [
                'success' => false,
                'error' => 'Workspace validation failed.',
                'details' => $exception->errors(),
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function buildAttributes(array $parameters): array
    {
        $organizationId = isset($parameters['organization_id']) ? (int) $parameters['organization_id'] : $this->organizationId;
        $teamId = array_key_exists('team_id', $parameters)
            ? ($parameters['team_id'] !== null ? (int) $parameters['team_id'] : null)
            : $this->teamId;

        if ($organizationId === null) {
            throw ValidationException::withMessages([
                'organization_id' => ['organization_id is required when no task or chat scope is provided.'],
            ]);
        }

        if ($this->organizationId !== null && $organizationId !== $this->organizationId) {
            throw ValidationException::withMessages([
                'organization_id' => ['Workspace creation is restricted to the current organization scope.'],
            ]);
        }

        if ($this->teamId !== null && $teamId !== null && $teamId !== $this->teamId) {
            throw ValidationException::withMessages([
                'team_id' => ['Workspace creation is restricted to the current team scope.'],
            ]);
        }

        $scopeType = (string) ($parameters['scope_type'] ?? '');
        if ($this->teamId !== null && $scopeType === 'org_shared') {
            throw ValidationException::withMessages([
                'scope_type' => ['org_shared workspaces cannot be created from a team-scoped run.'],
            ]);
        }

        $attributes = [
            'organization_id' => $organizationId,
            'team_id' => $teamId,
            'owner_user_id' => isset($parameters['owner_user_id']) ? (int) $parameters['owner_user_id'] : null,
            'name' => (string) ($parameters['name'] ?? ''),
            'slug' => isset($parameters['slug']) ? (string) $parameters['slug'] : null,
            'scope_type' => $scopeType,
        ];

        $metadata = $this->decodeMetadata($parameters['metadata'] ?? null);
        if ($metadata !== null) {
            $attributes['metadata'] = $metadata;
        }

        return $attributes;
    }

    private function decodeMetadata(mixed $metadata): ?array
    {
        if ($metadata === null || $metadata === '') {
            return null;
        }

        if (is_array($metadata)) {
            return $metadata;
        }

        if (! is_string($metadata)) {
            throw ValidationException::withMessages([
                'metadata' => ['metadata must be a JSON object string or object.'],
            ]);
        }

        $decoded = json_decode($metadata, true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'metadata' => ['metadata must be valid JSON object data.'],
            ]);
        }

        return $decoded;
    }
}
