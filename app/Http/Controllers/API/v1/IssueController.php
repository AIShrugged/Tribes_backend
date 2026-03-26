<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\IssueRequest;
use App\Http\Resources\API\v1\IssueResource;
use App\Http\Resources\API\v1\AgentTaskRunResource;
use App\Http\Responses\ApiResponse;
use App\Models\Issue;
use App\Models\User;
use App\Services\IssueAgentService;
use App\Services\TenantScopeValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class IssueController extends Controller
{
    public function __construct(
        private readonly TenantScopeValidator $tenantScopeValidator,
    ) {}

    public function index(IssueRequest $request): ApiResponse
    {
        $query = Issue::query()
            ->visibleTo($request->user())
            ->with('assignee')
            ->latest('id');

        $filters = $request->getIndexFilters();

        if ($filters['status']) {
            $query->where('status', $filters['status']);
        }

        if ($filters['type']) {
            $query->where('type', $filters['type']);
        }

        if ($filters['assignee_id']) {
            $query->where('assignee_id', $filters['assignee_id']);
        }

        if ($filters['organization_id']) {
            $query->where('organization_id', $filters['organization_id']);
        }

        if ($filters['team_id']) {
            $query->where('team_id', $filters['team_id']);
        }

        $count = (clone $query)->count();
        $issues = $query->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(IssueResource::collection($issues), $count);
    }

    public function store(IssueRequest $request): ApiResponse
    {
        $data = $request->getStoreData();
        $this->assertIssueScopeIsAllowed(
            $request->user(),
            (int) $data['organization_id'],
            isset($data['team_id']) ? (int) $data['team_id'] : null,
        );
        $this->assertAssigneeIsVisible($request->user(), $data['assignee_id'] ?? null);

        $issue = Issue::create([
            'user_id' => $request->user()->id,
            'organization_id' => $data['organization_id'],
            'team_id' => $data['team_id'] ?? null,
            'status' => $data['status'] ?? 'open',
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'assignee_id' => $data['assignee_id'] ?? null,
        ])->load('assignee');

        return ApiResponse::success(data: IssueResource::make($issue), status: 201);
    }

    public function show(IssueRequest $request, int $issue): ApiResponse
    {
        $task = $this->findVisibleIssue($request->user(), $issue);

        return ApiResponse::success(data: IssueResource::make($task->load('assignee')));
    }

    public function update(IssueRequest $request, int $issue): ApiResponse
    {
        $task = $this->findVisibleIssue($request->user(), $issue);
        $data = $request->getUpdateData();

        $this->assertIssueScopeIsAllowed(
            $request->user(),
            isset($data['organization_id']) ? (int) $data['organization_id'] : (int) $task->organization_id,
            array_key_exists('team_id', $data) ? ($data['team_id'] !== null ? (int) $data['team_id'] : null) : $task->team_id,
        );
        $this->assertAssigneeIsVisible($request->user(), $data['assignee_id'] ?? $task->assignee_id);
        $task->update($data);

        return ApiResponse::success(data: IssueResource::make($task->refresh()->load('assignee')));
    }

    public function destroy(IssueRequest $request, int $issue): ApiResponse
    {
        $task = $this->findVisibleIssue($request->user(), $issue);
        $task->delete();

        return ApiResponse::success();
    }

    public function dispatch(IssueRequest $request, int $issue, IssueAgentService $service): ApiResponse
    {
        $issue = $this->findVisibleIssue($request->user(), $issue);

        $run = $service->dispatch(
            $issue,
            $request->user(),
            $request->input('agent_profile_id'),
        );

        return ApiResponse::success(data: AgentTaskRunResource::make($run), status: 201);
    }

    private function findVisibleIssue(User $user, int $issueId): Issue
    {
        return Issue::query()
            ->visibleTo($user)
            ->with('assignee')
            ->findOrFail($issueId);
    }

    private function assertAssigneeIsVisible(User $user, ?int $assigneeId): void
    {
        if ($assigneeId === null) {
            return;
        }

        $organizationIds = $user->organizations()->pluck('organizations.id');
        $teamIds = $user->teams()->pluck('teams.id');

        $query = User::query()->whereKey($assigneeId);

        $query->where(function (Builder $builder) use ($organizationIds, $teamIds, $user): void {
            $builder->whereKey($user->id);

            if ($organizationIds->isNotEmpty()) {
                $builder->orWhereHas('organizations', function (Builder $relation) use ($organizationIds): void {
                    $relation->whereIn('organizations.id', $organizationIds);
                });
            }

            if ($teamIds->isNotEmpty()) {
                $builder->orWhereHas('teams', function (Builder $relation) use ($teamIds): void {
                    $relation->whereIn('teams.id', $teamIds);
                });
            }
        });

        if (! $query->exists()) {
            throw ValidationException::withMessages([
                'assignee_id' => ['The selected assignee is not available to the current user.'],
            ]);
        }
    }

    private function assertIssueScopeIsAllowed(User $user, int $organizationId, ?int $teamId): void
    {
        $this->tenantScopeValidator->assertScopeIsValid(
            $user,
            $organizationId,
            $teamId,
            allowUnbound: false,
        );

        if ($teamId !== null) {
            return;
        }

        if (! $user->isOrganizationManager($organizationId)) {
            throw ValidationException::withMessages([
                'organization_id' => ['Only organization managers can access organization-level issues without a team scope.'],
            ]);
        }
    }

}
