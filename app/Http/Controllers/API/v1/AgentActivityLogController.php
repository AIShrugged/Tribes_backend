<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AgentActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @group Agent Activity Logs
 */
class AgentActivityLogController extends Controller
{
    /**
     * List agent activity globally for the authenticated user
     *
     * Returns a list of agent actions the agent performed across all chats
     * owned by the authenticated user, ordered by most recent first.
     *
     * @authenticated
     *
     * @queryParam agent_run_uuid string Filter by specific agent run UUID. Example: 550e8400-e29b-41d4-a716-446655440000
     * @queryParam limit integer Number of records to return (default 50, max 200). Example: 50
     * @queryParam offset integer Number of records to skip (default 0). Example: 0
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "tool_name": "create_artifact",
     *       "description": "Создал артефакт: Критерии оценки",
     *       "success": true,
     *       "agent_run_uuid": "550e8400-e29b-41d4-a716-446655440000",
     *       "created_at": "2026-03-24T14:00:00.000000Z"
     *     }
     *   ]
     * }
     */
    public function index(Request $request): ApiResponse
    {
        $query = AgentActivityLog::where('user_id', Auth::id())
            ->orderByDesc('created_at');

        if ($request->has('agent_run_uuid')) {
            $query->where('agent_run_uuid', $request->input('agent_run_uuid'));
        }

        if ($request->has('agent_task_run_id')) {
            $query->where('agent_task_run_id', $request->integer('agent_task_run_id'));
        }

        $limit = min((int) $request->input('limit', 50), 200);
        $offset = (int) $request->input('offset', 0);

        $count = $query->count();
        $logs = $query->offset($offset)->limit($limit)->get([
            'id',
            'tool_name',
            'description',
            'success',
            'agent_run_uuid',
            'agent_task_run_id',
            'created_at',
        ]);

        return ApiResponse::list($logs, $count);
    }
}
