<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\AgentProfileRequest;
use App\Http\Resources\API\v1\AgentProfileResource;
use App\Http\Responses\ApiResponse;
use App\Models\AgentProfile;
use App\Models\User;
use App\Exceptions\AppException;
use App\Services\JsonSchemaValidationService;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;

#[Group('Agent Profiles', 'Agent profile catalog, execution defaults, and frontend-driven JSON schemas.')]
class AgentProfileController extends Controller
{
    #[Endpoint(title: 'List agent profiles', description: 'Returns agent profiles. Can be filtered by enabled state.')]
    #[QueryParameter('enabled', 'Filter by enabled state.', type: 'bool', example: true)]
    #[Response(
        200,
        'Paginated list envelope.',
        type: 'array{success: bool, data: array<int, \App\Http\Resources\API\v1\AgentProfileResource>, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function index(AgentProfileRequest $request): ApiResponse
    {
        $this->assertUserCanManageProfiles($request->user());

        $query = AgentProfile::query();

        if ($request->has('enabled')) {
            $query->where('enabled', $request->boolean('enabled'));
        }

        $count = $query->count();
        $profiles = $query->orderBy('name')
            ->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(AgentProfileResource::collection($profiles), $count);
    }

    #[Endpoint(title: 'Create agent profile', description: 'Creates an agent profile with JSON schemas for UI config and task payload validation.')]
    #[BodyParameter('key', 'Stable profile key.', required: true, type: 'string', example: 'github-reviewer')]
    #[BodyParameter('name', 'Human-readable profile name.', required: true, type: 'string', example: 'GitHub Reviewer')]
    #[BodyParameter('description', 'Optional profile description.', required: false, type: 'string', example: 'Reviews repositories and stores architecture facts in memory.')]
    #[BodyParameter('system_prompt', 'Base system prompt for this profile.', required: false, type: 'string', example: 'You are a repository reviewer focused on correctness and architecture.')]
    #[BodyParameter('config_schema', 'JSON Schema describing frontend-configurable profile fields.', required: false, type: 'object', example: ['type' => 'object', 'properties' => ['scan_mode' => ['type' => 'string']]])]
    #[BodyParameter('task_payload_schema', 'JSON Schema used to validate task payloads.', required: false, type: 'object', example: ['type' => 'object', 'required' => ['provider', 'owner', 'repo']])]
    #[BodyParameter('execution_mode', 'Execution mode for tasks using this profile.', required: false, type: 'string', example: 'isolated')]
    #[BodyParameter('sandbox_profile', 'Sandbox image/profile identifier.', required: false, type: 'string', example: 'python_basic')]
    #[BodyParameter('allowed_tools', 'Host tools that isolated runs may invoke.', required: false, type: 'array', example: ['get_user_insights', 'search_memory'])]
    #[BodyParameter('allowed_outbound_hosts', 'Outbound hostname allowlist for sandbox networking.', required: false, type: 'array', example: ['api.github.com', 'github.com', '*.githubusercontent.com'])]
    #[BodyParameter('default_model', 'Default LLM model for this profile.', required: false, type: 'string', example: 'claude-sonnet-4-20250514')]
    #[BodyParameter('enabled', 'Whether the profile is active.', required: false, type: 'bool', example: true)]
    #[BodyParameter('metadata', 'Additional free-form profile metadata.', required: false, type: 'object', example: ['scan_mode' => 'architecture_snapshot'])]
    #[Response(
        201,
        'Created profile envelope.',
        type: 'array{success: bool, data: \App\Http\Resources\API\v1\AgentProfileResource, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function store(AgentProfileRequest $request, JsonSchemaValidationService $schemaValidation): ApiResponse
    {
        $this->assertUserCanManageProfiles($request->user());

        $data = $request->getStoreData();

        $schemaValidation->assertValidSchema($data['config_schema'] ?? null, 'config_schema');
        $schemaValidation->assertValidSchema($data['task_payload_schema'] ?? null, 'task_payload_schema');

        $profile = AgentProfile::create($data);

        return ApiResponse::success(data: AgentProfileResource::make($profile), status: 201);
    }

    #[Endpoint(title: 'Show agent profile', description: 'Returns a single agent profile, including frontend schemas and runtime defaults.')]
    #[PathParameter('agentProfile', 'Agent profile ID.', required: true, type: 'integer', example: 1)]
    #[Response(
        200,
        'Single profile envelope.',
        type: 'array{success: bool, data: \App\Http\Resources\API\v1\AgentProfileResource, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function show(AgentProfile $agentProfile): ApiResponse
    {
        $this->assertUserCanManageProfiles(request()->user());

        return ApiResponse::success(data: AgentProfileResource::make($agentProfile));
    }

    #[Endpoint(title: 'Update agent profile', description: 'Updates execution defaults, JSON schemas, or metadata for an existing profile.')]
    #[PathParameter('agentProfile', 'Agent profile ID.', required: true, type: 'integer', example: 1)]
    #[BodyParameter('key', 'Stable profile key.', required: false, type: 'string', example: 'github-reviewer')]
    #[BodyParameter('name', 'Human-readable profile name.', required: false, type: 'string', example: 'GitHub Reviewer')]
    #[BodyParameter('description', 'Optional profile description.', required: false, type: 'string', example: 'Reviews repositories and stores architecture facts in memory.')]
    #[BodyParameter('system_prompt', 'Base system prompt for this profile.', required: false, type: 'string', example: 'You are a repository reviewer focused on correctness and architecture.')]
    #[BodyParameter('config_schema', 'JSON Schema describing frontend-configurable profile fields.', required: false, type: 'object', example: ['type' => 'object', 'properties' => ['scan_mode' => ['type' => 'string']]])]
    #[BodyParameter('task_payload_schema', 'JSON Schema used to validate task payloads.', required: false, type: 'object', example: ['type' => 'object', 'required' => ['provider', 'owner', 'repo']])]
    #[BodyParameter('execution_mode', 'Execution mode for tasks using this profile.', required: false, type: 'string', example: 'isolated')]
    #[BodyParameter('sandbox_profile', 'Sandbox image/profile identifier.', required: false, type: 'string', example: 'python_basic')]
    #[BodyParameter('allowed_tools', 'Host tools that isolated runs may invoke.', required: false, type: 'array', example: ['get_user_insights', 'search_memory'])]
    #[BodyParameter('allowed_outbound_hosts', 'Outbound hostname allowlist for sandbox networking.', required: false, type: 'array', example: ['api.github.com', 'github.com', '*.githubusercontent.com'])]
    #[BodyParameter('default_model', 'Default LLM model for this profile.', required: false, type: 'string', example: 'claude-sonnet-4-20250514')]
    #[BodyParameter('enabled', 'Whether the profile is active.', required: false, type: 'bool', example: true)]
    #[BodyParameter('metadata', 'Additional free-form profile metadata.', required: false, type: 'object', example: ['scan_mode' => 'architecture_snapshot'])]
    #[Response(
        200,
        'Updated profile envelope.',
        type: 'array{success: bool, data: \App\Http\Resources\API\v1\AgentProfileResource, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function update(
        AgentProfileRequest $request,
        AgentProfile $agentProfile,
        JsonSchemaValidationService $schemaValidation
    ): ApiResponse {
        $this->assertUserCanManageProfiles($request->user());

        $data = $request->getUpdateData();

        if (array_key_exists('config_schema', $data)) {
            $schemaValidation->assertValidSchema($data['config_schema'], 'config_schema');
        }

        if (array_key_exists('task_payload_schema', $data)) {
            $schemaValidation->assertValidSchema($data['task_payload_schema'], 'task_payload_schema');
        }

        $agentProfile->update($data);

        return ApiResponse::success(data: AgentProfileResource::make($agentProfile->refresh()));
    }

    #[Endpoint(title: 'Delete agent profile', description: 'Deletes an agent profile.')]
    #[PathParameter('agentProfile', 'Agent profile ID.', required: true, type: 'integer', example: 1)]
    #[Response(
        200,
        'Successful delete envelope.',
        type: 'array{success: bool, data: null, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function destroy(AgentProfile $agentProfile): ApiResponse
    {
        $this->assertUserCanManageProfiles(request()->user());

        $agentProfile->delete();

        return ApiResponse::success();
    }

    #[Endpoint(title: 'Validate task payload for profile', description: 'Validates an arbitrary task payload against the profile task payload JSON Schema without creating a task.')]
    #[PathParameter('agentProfile', 'Agent profile ID.', required: true, type: 'integer', example: 1)]
    #[BodyParameter('payload', 'Task payload to validate against the profile schema.', required: true, type: 'object', example: ['provider' => 'github', 'owner' => 'acme', 'repo' => 'api'])]
    #[Response(
        200,
        'Validation result envelope.',
        type: 'array{success: bool, data: array{valid: bool}, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function validatePayload(
        AgentProfileRequest $request,
        AgentProfile $agentProfile,
        JsonSchemaValidationService $schemaValidation
    ): ApiResponse {
        $this->assertUserCanManageProfiles($request->user());

        $schemaValidation->validatePayload($request->getPayloadData(), $agentProfile->task_payload_schema);

        return ApiResponse::success(data: [
            'valid' => true,
        ]);
    }

    private function assertUserCanManageProfiles(User $user): void
    {
        $managesAnyOrganization = $user->organizations()
            ->wherePivot('role', \App\Enums\UserRole::MANAGER->value)
            ->exists();

        if ($managesAnyOrganization) {
            return;
        }

        throw new AppException(
            'Only organization managers can manage agent profiles.',
            'AGENT_PROFILE_MANAGER_REQUIRED',
            403,
        );
    }
}
