<?php

namespace App\Http\Controllers\API\v1;

use App\Enums\AgentScheduleType;
use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\AgentTaskRequest;
use App\Http\Resources\API\v1\AgentTaskResource;
use App\Http\Responses\ApiResponse;
use App\Models\AgentProfile;
use App\Models\AgentTask;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\JsonSchemaValidationService;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Validation\ValidationException;

#[Group('Agent Tasks', 'Scheduled and one-off agent task management.')]
class AgentTaskController extends Controller
{
    #[Endpoint(title: 'List agent tasks', description: 'Returns agent tasks belonging to the authenticated user.')]
    #[QueryParameter('enabled', 'Filter by enabled state.', type: 'bool', example: true)]
    #[QueryParameter('schedule_type', 'Filter by schedule type.', type: 'string', example: 'interval')]
    #[QueryParameter('agent_profile_id', 'Filter by agent profile.', type: 'integer', example: 1)]
    #[Response(
        200,
        'Paginated task list envelope.',
        type: 'array{success: bool, data: array<int, \App\Http\Resources\API\v1\AgentTaskResource>, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function index(AgentTaskRequest $request): ApiResponse
    {
        $query = $request->user()
            ->agentTasks()
            ->with(['profile', 'latestRun'])
            ->latest('id');

        $filters = $request->getIndexFilters();

        if ($filters['enabled'] !== null) {
            $query->where('enabled', $filters['enabled']);
        }

        if ($filters['schedule_type']) {
            $query->where('schedule_type', $filters['schedule_type']);
        }

        if ($filters['agent_profile_id']) {
            $query->where('agent_profile_id', $filters['agent_profile_id']);
        }

        $count = (clone $query)->count();
        $tasks = $query->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(AgentTaskResource::collection($tasks), $count);
    }

    #[Endpoint(title: 'Create agent task', description: 'Creates a one-off or interval agent task for the authenticated user.')]
    #[BodyParameter('name', 'Task name.', required: true, type: 'string', example: 'Scan acme/api repository')]
    #[BodyParameter('prompt', 'Task prompt.', required: true, type: 'string', example: 'Inspect the repository and update memory with architecture facts.')]
    #[BodyParameter('agent_profile_id', 'Optional agent profile id.', required: false, type: 'integer', example: 1)]
    #[BodyParameter('input_payload', 'Structured task payload.', required: false, type: 'object', example: ['provider' => 'github', 'owner' => 'acme', 'repo' => 'api'])]
    #[BodyParameter('schedule_type', 'Scheduling mode.', required: true, type: 'string', example: 'interval')]
    #[BodyParameter('interval_seconds', 'Interval in seconds for recurring tasks.', required: false, type: 'integer', example: 21600)]
    #[BodyParameter('next_run_at', 'Initial run time. Defaults to now.', required: false, type: 'string', format: 'date-time', example: '2026-03-17T12:00:00Z')]
    #[BodyParameter('execution_mode', 'Task-level execution mode override.', required: false, type: 'string', example: 'isolated')]
    #[BodyParameter('sandbox_profile', 'Task-level sandbox profile override.', required: false, type: 'string', example: 'spodial-agent-python:latest')]
    #[BodyParameter('allowed_tools', 'Task-level tool allowlist override.', required: false, type: 'array', example: ['get_user_insights'])]
    #[BodyParameter('allowed_outbound_hosts', 'Task-level outbound host allowlist override.', required: false, type: 'array', example: ['api.github.com', 'github.com'])]
    #[BodyParameter('enabled', 'Whether the task is enabled.', required: false, type: 'bool', example: true)]
    #[BodyParameter('max_attempts', 'Maximum queue attempts.', required: false, type: 'integer', example: 3)]
    #[BodyParameter('metadata', 'Free-form task metadata.', required: false, type: 'object', example: ['max_iterations' => 8])]
    #[Response(
        201,
        'Created task envelope.',
        type: 'array{success: bool, data: \App\Http\Resources\API\v1\AgentTaskResource, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function store(AgentTaskRequest $request, JsonSchemaValidationService $schemaValidation): ApiResponse
    {
        $data = $this->preparePersistedData(
            $request->getStoreData(),
            $schemaValidation,
            userId: $request->user()->id,
        );

        $task = AgentTask::create($data)->load(['profile', 'latestRun']);

        return ApiResponse::success(data: AgentTaskResource::make($task), status: 201);
    }

    #[Endpoint(title: 'Show agent task', description: 'Returns a single agent task owned by the authenticated user.')]
    #[PathParameter('agentTask', 'Agent task id.', required: true, type: 'integer', example: 1)]
    #[Response(
        200,
        'Single task envelope.',
        type: 'array{success: bool, data: \App\Http\Resources\API\v1\AgentTaskResource, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function show(AgentTaskRequest $request, int $agentTask): ApiResponse
    {
        $task = $this->findUserTask($request->user()->id, $agentTask);

        return ApiResponse::success(data: AgentTaskResource::make($task->load(['profile', 'latestRun'])));
    }

    #[Endpoint(title: 'Update agent task', description: 'Updates an existing agent task owned by the authenticated user.')]
    #[PathParameter('agentTask', 'Agent task id.', required: true, type: 'integer', example: 1)]
    #[Response(
        200,
        'Updated task envelope.',
        type: 'array{success: bool, data: \App\Http\Resources\API\v1\AgentTaskResource, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function update(AgentTaskRequest $request, int $agentTask, JsonSchemaValidationService $schemaValidation): ApiResponse
    {
        $task = $this->findUserTask($request->user()->id, $agentTask);

        $data = $this->preparePersistedData(
            $request->getUpdateData(),
            $schemaValidation,
            userId: $request->user()->id,
            existingTask: $task,
        );

        $task->update($data);

        return ApiResponse::success(data: AgentTaskResource::make($task->refresh()->load(['profile', 'latestRun'])));
    }

    #[Endpoint(title: 'Delete agent task', description: 'Deletes an agent task and its run history.')]
    #[PathParameter('agentTask', 'Agent task id.', required: true, type: 'integer', example: 1)]
    #[Response(
        200,
        'Deleted task envelope.',
        type: 'array{success: bool, data: null, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function destroy(AgentTaskRequest $request, int $agentTask): ApiResponse
    {
        $task = $this->findUserTask($request->user()->id, $agentTask);
        $task->delete();

        return ApiResponse::success();
    }

    private function findUserTask(int $userId, int $taskId): AgentTask
    {
        return AgentTask::query()
            ->where('user_id', $userId)
            ->findOrFail($taskId);
    }

    private function preparePersistedData(
        array $data,
        JsonSchemaValidationService $schemaValidation,
        int $userId,
        ?AgentTask $existingTask = null,
    ): array {
        $profileId = $data['agent_profile_id'] ?? $existingTask?->agent_profile_id;
        $profile = $profileId ? AgentProfile::query()->findOrFail($profileId) : null;

        $payload = $data['input_payload']
            ?? $existingTask?->input_payload
            ?? [];

        $schemaValidation->validatePayload(
            is_array($payload) ? $payload : [],
            $profile?->task_payload_schema,
        );

        $scheduleType = $data['schedule_type'] ?? $existingTask?->schedule_type?->value ?? AgentScheduleType::ONE_OFF->value;
        $intervalSeconds = array_key_exists('interval_seconds', $data)
            ? $data['interval_seconds']
            : $existingTask?->interval_seconds;

        if ($scheduleType === AgentScheduleType::INTERVAL->value) {
            if (! $intervalSeconds || (int) $intervalSeconds < 1) {
                throw new AppException(
                    'The interval_seconds field is required for interval tasks.',
                    'AGENT_TASK_INTERVAL_REQUIRED',
                    422,
                );
            }
        } else {
            $intervalSeconds = null;
        }

        $nextRunAt = array_key_exists('next_run_at', $data)
            ? $data['next_run_at']
            : $existingTask?->next_run_at;

        if ($existingTask === null && $nextRunAt === null) {
            $nextRunAt = now();
        }

        $data['user_id'] = $userId;
        $data['agent_profile_id'] = $profile?->id;
        $data['schedule_type'] = $scheduleType;
        $data['interval_seconds'] = $intervalSeconds;
        $data['next_run_at'] = $nextRunAt;
        $this->assertTenantScopeIsValid(
            User::query()->findOrFail($userId),
            isset($data['organization_id']) ? (int) $data['organization_id'] : $existingTask?->organization_id,
            isset($data['team_id']) ? (int) $data['team_id'] : $existingTask?->team_id,
        );

        return $data;
    }

    private function assertTenantScopeIsValid(User $user, ?int $organizationId, ?int $teamId): void
    {
        if ($organizationId === null) {
            throw ValidationException::withMessages([
                'organization_id' => ['Organization is required for agent tasks.'],
            ]);
        }

        $organization = Organization::query()->findOrFail($organizationId);
        if (! $user->isOrganizationMember($organization)) {
            throw ValidationException::withMessages([
                'organization_id' => ['You do not belong to the selected organization.'],
            ]);
        }

        if ($teamId === null) {
            return;
        }

        $team = Team::query()->findOrFail($teamId);

        if ((int) $team->organization_id !== (int) $organization->id) {
            throw ValidationException::withMessages([
                'team_id' => ['Team does not belong to the selected organization.'],
            ]);
        }

        if (! $user->isTeamMember($team)) {
            throw ValidationException::withMessages([
                'team_id' => ['You do not belong to the selected team.'],
            ]);
        }
    }
}
