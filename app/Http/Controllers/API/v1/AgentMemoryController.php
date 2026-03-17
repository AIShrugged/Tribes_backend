<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\AgentMemoryRequest;
use App\Http\Resources\API\v1\AgentMemoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\AgentTask;
use App\Services\AgentMemoryLookupService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;

#[Group('Agent Memory', 'Read access to memories collected and used by agent profiles and tasks.')]
class AgentMemoryController extends Controller
{
    public function __construct(
        private readonly AgentMemoryLookupService $memoryLookupService,
    ) {}

    #[Endpoint(title: 'List agent memories', description: 'Returns memories accessible to the authenticated user through their agent tasks.')]
    #[QueryParameter('agent_profile_id', 'Filter by agent profile id.', type: 'integer', example: 1)]
    #[QueryParameter('scope_type', 'Filter by scope type.', type: 'string', example: 'repository')]
    #[QueryParameter('scope_key', 'Filter by scope key.', type: 'string', example: 'github:acme/api')]
    #[QueryParameter('kind', 'Filter by memory kind.', type: 'string', example: 'architecture_fact')]
    #[QueryParameter('active', 'Filter by active state.', type: 'bool', example: true)]
    #[Response(
        200,
        'Memory list envelope.',
        type: 'array{success: bool, data: array<int, \App\Http\Resources\API\v1\AgentMemoryResource>, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function index(AgentMemoryRequest $request): ApiResponse
    {
        $query = $this->accessibleMemoriesQuery($request->user()->id)
            ->with('profile')
            ->orderByDesc('priority')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id');

        $filters = $request->getIndexFilters();

        foreach (['agent_profile_id', 'scope_type', 'scope_key', 'kind'] as $field) {
            if ($filters[$field]) {
                $query->where($field, $filters[$field]);
            }
        }

        if ($filters['active'] !== null) {
            $query->where('active', $filters['active']);
        }

        $count = (clone $query)->count();
        $memories = $query->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(AgentMemoryResource::collection($memories), $count);
    }

    #[Endpoint(title: 'Show agent memory', description: 'Returns a single memory if it is accessible to the authenticated user.')]
    #[PathParameter('agentMemory', 'Agent memory id.', required: true, type: 'integer', example: 1)]
    #[Response(
        200,
        'Single memory envelope.',
        type: 'array{success: bool, data: \App\Http\Resources\API\v1\AgentMemoryResource, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function show(AgentMemoryRequest $request, int $agentMemory): ApiResponse
    {
        $memory = $this->accessibleMemoriesQuery($request->user()->id)
            ->with('profile')
            ->findOrFail($agentMemory);

        return ApiResponse::success(data: AgentMemoryResource::make($memory));
    }

    #[Endpoint(title: 'List profile memories', description: 'Returns memories for an agent profile if the user has at least one task using that profile.')]
    #[PathParameter('agentProfile', 'Agent profile id.', required: true, type: 'integer', example: 1)]
    #[Response(
        200,
        'Profile memory list envelope.',
        type: 'array{success: bool, data: array<int, \App\Http\Resources\API\v1\AgentMemoryResource>, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function profileIndex(AgentMemoryRequest $request, int $agentProfile): ApiResponse
    {
        $this->findAccessibleProfile($request->user()->id, $agentProfile);

        $query = $this->accessibleMemoriesQuery($request->user()->id)
            ->where('agent_profile_id', $agentProfile)
            ->with('profile')
            ->orderByDesc('priority')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id');

        $count = (clone $query)->count();
        $memories = $query->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(AgentMemoryResource::collection($memories), $count);
    }

    #[Endpoint(title: 'List task memories', description: 'Returns memories relevant to a concrete agent task: profile, repository, and task-scoped memories the agent can use during execution.')]
    #[PathParameter('agentTask', 'Agent task id.', required: true, type: 'integer', example: 1)]
    #[Response(
        200,
        'Task memory list envelope.',
        type: 'array{success: bool, data: array<int, \App\Http\Resources\API\v1\AgentMemoryResource>, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function taskIndex(AgentMemoryRequest $request, int $agentTask): ApiResponse
    {
        $task = AgentTask::query()
            ->with('profile')
            ->where('user_id', $request->user()->id)
            ->findOrFail($agentTask);

        $query = $this->taskRelevantMemoriesQuery($task)
            ->with('profile')
            ->orderByDesc('priority')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id');

        $count = (clone $query)->count();
        $memories = $query->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(AgentMemoryResource::collection($memories), $count);
    }

    private function accessibleMemoriesQuery(int $userId): Builder
    {
        return $this->memoryLookupService->accessibleMemoriesQuery($userId);
    }

    private function findAccessibleProfile(int $userId, int $profileId)
    {
        return $this->memoryLookupService->findAccessibleProfile($userId, $profileId);
    }

    private function taskRelevantMemoriesQuery(AgentTask $task): Builder
    {
        return $this->memoryLookupService->taskRelevantMemoriesQuery($task);
    }
}
