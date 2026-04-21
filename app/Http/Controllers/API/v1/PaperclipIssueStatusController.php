<?php

namespace App\Http\Controllers\API\v1;

use App\Enums\AgentTaskRunStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\PaperclipIssueStatusRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AgentTaskRun;
use App\Services\PaperclipCallbackTokenService;
use App\Services\PaperclipTaskRunStatusSyncService;
use Illuminate\Support\Facades\Log;

/**
 * @hideFromAPIDocumentation
 */
class PaperclipIssueStatusController extends Controller
{
    public function store(
        PaperclipIssueStatusRequest $request,
        string $paperclipIssueId,
        PaperclipCallbackTokenService $tokenService,
        PaperclipTaskRunStatusSyncService $syncService,
    ): ApiResponse {
        $run = AgentTaskRun::with('task')
            ->where('paperclip_issue_id', $paperclipIssueId)
            ->latest('id')
            ->first();

        if (! $run) {
            return ApiResponse::error('Paperclip issue not found.', status: 404);
        }

        if (! $run->task) {
            return ApiResponse::error('Paperclip task not found.', status: 404);
        }

        $plainToken = (string) $request->header('X-Paperclip-Run-Token', '');
        if (! $tokenService->validate($run, $plainToken)) {
            return ApiResponse::error('Missing or invalid Paperclip callback token.', status: 401);
        }

        $terminalStatuses = [
            AgentTaskRunStatus::COMPLETED,
            AgentTaskRunStatus::FAILED,
            AgentTaskRunStatus::PAUSED,
        ];

        if (in_array($run->status, $terminalStatuses, true)) {
            return ApiResponse::success(data: [
                'applied' => false,
                'status' => $run->status?->value ?? $run->status,
                'paperclip_issue_id' => $paperclipIssueId,
            ]);
        }

        $applied = $syncService->applyCallback(
            $run,
            $request->getStatus(),
            $request->getComment(),
            $request->getArtifacts(),
        );

        Log::info('Paperclip issue status callback received', [
            'agent_task_run_id' => $run->id,
            'paperclip_issue_id' => $paperclipIssueId,
            'status' => $request->getStatus(),
            'applied' => $applied,
        ]);

        return ApiResponse::success(data: [
            'applied' => $applied,
            'status' => $run->fresh()->status?->value ?? $run->status,
            'paperclip_issue_id' => $paperclipIssueId,
        ]);
    }
}
