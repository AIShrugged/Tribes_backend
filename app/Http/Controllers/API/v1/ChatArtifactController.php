<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Chat;
use App\Services\Artifact\ArtifactStateService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * @group Wanda Chat
 */
class ChatArtifactController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ArtifactStateService $artifactStateService,
    ) {
    }

    /**
     * Get artifact state
     *
     * Returns the current artifact state for a chat: all artifacts and layout order.
     *
     * @subgroup Artifacts
     * @authenticated
     *
     * @urlParam chat integer required The Chat ID. Example: 1
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": {
     *     "artifacts": {
     *       "task_table_abc123": {
     *         "id": "task_table_abc123",
     *         "type": "task_table",
     *         "title": "Задачи со встречи",
     *         "data": {"columns": ["task", "assignee", "due_date"], "rows": []},
     *         "status": "ready"
     *       }
     *     },
     *     "layout": {
     *       "items": [{"id": "task_table_abc123"}]
     *     }
     *   },
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [Chat] 1"}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function index(Chat $chat): ApiResponse
    {
        $this->authorize('view', $chat);

        $state = $this->artifactStateService->loadState($chat);

        return ApiResponse::success(data: $state);
    }
}
