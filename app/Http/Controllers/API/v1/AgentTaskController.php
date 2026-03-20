<?php

namespace App\Http\Controllers\API\v1;

use App\Enums\AgentScheduleType;
use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\AgentTaskRequest;
use App\Http\Resources\API\v1\AgentTaskResource;
use App\Http\Resources\API\v1\AgentTaskRunResource;
use App\Http\Responses\ApiResponse;
use App\Models\AgentProfile;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\AgentTaskMutationService;
use App\Services\AgentTaskSchedulerService;
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
        $managedOrganizationIds = $this->managedOrganizationIds($request->user());
        $this->assertUserManagesAnyOrganization($managedOrganizationIds);

        $query = AgentTask::query()
            ->whereIn('organization_id', $managedOrganizationIds)
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
    public function store(AgentTaskRequest $request, AgentTaskMutationService $taskMutationService): ApiResponse
    {
        $data = $taskMutationService->preparePersistedData(
            $request->getStoreData(),
            $request->user(),
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
        $task = $this->findManagedTask($request->user(), $agentTask);

        return ApiResponse::success(data: AgentTaskResource::make($task->load(['profile', 'latestRun'])));
    }

    #[Endpoint(title: 'Update agent task', description: 'Updates an existing agent task owned by the authenticated user.')]
    #[PathParameter('agentTask', 'Agent task id.', required: true, type: 'integer', example: 1)]
    #[Response(
        200,
        'Updated task envelope.',
        type: 'array{success: bool, data: \App\Http\Resources\API\v1\AgentTaskResource, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function update(AgentTaskRequest $request, int $agentTask, AgentTaskMutationService $taskMutationService): ApiResponse
    {
        $task = $this->findManagedTask($request->user(), $agentTask);

        $data = $taskMutationService->preparePersistedData(
            $request->getUpdateData(),
            $request->user(),
            $task,
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
        $task = $this->findManagedTask($request->user(), $agentTask);
        $task->delete();

        return ApiResponse::success();
    }

    public function runs(AgentTaskRequest $request, int $agentTask): ApiResponse
    {
        $task = $this->findManagedTask($request->user(), $agentTask);
        $query = $task->runs()->latest('id');

        $count = (clone $query)->count();
        $runs = $query->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(AgentTaskRunResource::collection($runs), $count);
    }

    public function showRun(AgentTaskRequest $request, int $agentTask, int $run): ApiResponse
    {
        $task = $this->findManagedTask($request->user(), $agentTask);
        $taskRun = $task->runs()->findOrFail($run);

        return ApiResponse::success(data: AgentTaskRunResource::make($taskRun));
    }

    public function dispatch(AgentTaskRequest $request, int $agentTask, AgentTaskSchedulerService $scheduler): ApiResponse
    {
        $task = $this->findManagedTask($request->user(), $agentTask);
        $run = $scheduler->dispatchTaskNow($task);

        if (! $run instanceof AgentTaskRun) {
            throw new AppException(
                'Task is already running or disabled.',
                'AGENT_TASK_DISPATCH_UNAVAILABLE',
                409,
            );
        }

        return ApiResponse::success(data: AgentTaskRunResource::make($run), status: 201);
    }

    public function meta(): ApiResponse
    {
        $this->assertUserManagesAnyOrganization($this->managedOrganizationIds(request()->user()));

        return ApiResponse::success(data: [
            'schedule_types' => array_map(fn ($case) => $case->value, \App\Enums\AgentScheduleType::cases()),
            'execution_modes' => array_map(fn ($case) => $case->value, \App\Enums\AgentTaskExecutionMode::cases()),
            'task_types' => array_map(fn ($case) => $case->value, \App\Enums\AgentTaskType::cases()),
            'output_modes' => array_map(fn ($case) => $case->value, \App\Enums\OutputMode::cases()),
            'metadata_schema' => AgentTaskResource::metadataSchema(),
        ]);
    }

    private function findManagedTask(User $user, int $taskId): AgentTask
    {
        $managedOrganizationIds = $this->managedOrganizationIds($user);
        $this->assertUserManagesAnyOrganization($managedOrganizationIds);

        return AgentTask::query()
            ->whereIn('organization_id', $managedOrganizationIds)
            ->findOrFail($taskId);
    }

    private function managedOrganizationIds(User $user): array
    {
        return $user->organizations()
            ->wherePivot('role', \App\Enums\UserRole::MANAGER->value)
            ->pluck('organizations.id')
            ->all();
    }

    private function assertUserManagesAnyOrganization(array $managedOrganizationIds): void
    {
        if ($managedOrganizationIds !== []) {
            return;
        }

        throw new AppException(
            'Only organization managers can manage agent tasks and runs.',
            'AGENT_TASK_MANAGER_REQUIRED',
            403,
        );
    }
}
