<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\WorkspaceRequest;
use App\Http\Resources\API\v1\WorkspacePermissionResource;
use App\Http\Resources\API\v1\WorkspaceResource;
use App\Http\Responses\ApiResponse;
use App\Models\Workspace;
use App\Models\WorkspacePermission;
use App\Services\Workspace\WorkspaceAccessService;
use App\Services\Workspace\WorkspaceProvisioningService;
use App\Services\Workspace\WorkspaceService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;

class WorkspaceController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly WorkspaceAccessService $workspaceAccessService,
        private readonly WorkspaceProvisioningService $workspaceProvisioningService,
        private readonly WorkspaceService $workspaceService,
    ) {
        $this->authorizeResource(Workspace::class, 'workspace');
    }

    public function index(WorkspaceRequest $request): ApiResponse
    {
        $workspaces = $this->workspaceAccessService->listAccessibleWorkspaces(Auth::user());

        return ApiResponse::list(
            WorkspaceResource::collection(
                $workspaces->slice($request->getOffset(), $request->getLimit())->values()
            ),
            $workspaces->count()
        );
    }

    public function store(WorkspaceRequest $request): ApiResponse
    {
        $workspace = $this->workspaceProvisioningService->createWorkspace(Auth::user(), $request->getStoreData());

        return ApiResponse::success(
            data: WorkspaceResource::make($workspace->load('permissions'))
        );
    }

    public function show(WorkspaceRequest $request, Workspace $workspace): ApiResponse
    {
        return ApiResponse::success(
            data: WorkspaceResource::make($workspace->load('permissions'))
        );
    }

    public function update(WorkspaceRequest $request, Workspace $workspace): ApiResponse
    {
        $workspace = $this->workspaceProvisioningService->updateWorkspace(Auth::user(), $workspace, $request->getUpdateData());

        return ApiResponse::success(
            data: WorkspaceResource::make($workspace->load('permissions'))
        );
    }

    public function destroy(WorkspaceRequest $request, Workspace $workspace): ApiResponse
    {
        $workspace->delete();

        return ApiResponse::success();
    }

    public function contents(WorkspaceRequest $request, Workspace $workspace): ApiResponse
    {
        abort_unless($this->workspaceAccessService->abilitiesForUser(Auth::user(), $workspace)['list'], 403);

        return ApiResponse::success(
            data: $this->workspaceService->listContents($workspace, (string) $request->query('path', ''))
        );
    }

    public function readFile(WorkspaceRequest $request, Workspace $workspace): ApiResponse
    {
        abort_unless($this->workspaceAccessService->abilitiesForUser(Auth::user(), $workspace)['read'], 403);

        return ApiResponse::success(data: $this->workspaceService->readFileWithLimit(
            $workspace,
            $request->getFilePath(),
            $request->getMaxBytes(),
        ));
    }

    public function writeFile(WorkspaceRequest $request, Workspace $workspace): ApiResponse
    {
        abort_unless($this->workspaceAccessService->abilitiesForUser(Auth::user(), $workspace)['write'], 403);

        $this->workspaceService->writeFile($workspace, $request->getFilePath(), $request->getFileContents());

        return ApiResponse::success(data: ['path' => $request->getFilePath()]);
    }

    public function deleteFile(WorkspaceRequest $request, Workspace $workspace): ApiResponse
    {
        abort_unless($this->workspaceAccessService->abilitiesForUser(Auth::user(), $workspace)['delete'], 403);

        return ApiResponse::success(data: [
            'deleted' => $this->workspaceService->delete($workspace, $request->getFilePath()),
            'path' => $request->getFilePath(),
        ]);
    }

    public function createDirectory(WorkspaceRequest $request, Workspace $workspace): ApiResponse
    {
        abort_unless($this->workspaceAccessService->abilitiesForUser(Auth::user(), $workspace)['write'], 403);

        return ApiResponse::success(data: [
            'created' => $this->workspaceService->makeDirectory($workspace, $request->getFilePath()),
            'path' => $request->getFilePath(),
        ]);
    }

    public function storePermission(WorkspaceRequest $request, Workspace $workspace): ApiResponse
    {
        abort_unless($this->workspaceAccessService->abilitiesForUser(Auth::user(), $workspace)['admin'], 403);

        $data = $request->getPermissionData();
        $permission = $this->workspaceProvisioningService->grantOrUpdatePermission(
            $workspace,
            $data['principal_type'],
            $data['principal_id'],
            $data['abilities'],
        );

        return ApiResponse::success(data: WorkspacePermissionResource::make($permission));
    }

    public function destroyPermission(WorkspaceRequest $request, Workspace $workspace, WorkspacePermission $workspacePermission): ApiResponse
    {
        abort_unless($workspacePermission->workspace_id === $workspace->id, 404);
        abort_unless($this->workspaceAccessService->abilitiesForUser(Auth::user(), $workspace)['admin'], 403);

        $this->workspaceProvisioningService->deletePermission($workspacePermission);

        return ApiResponse::success();
    }
}
