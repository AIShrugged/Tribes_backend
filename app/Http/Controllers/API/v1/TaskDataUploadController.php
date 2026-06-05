<?php

namespace App\Http\Controllers\API\v1;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\UploadTaskDataRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Issue;
use App\Models\TaskDataUpload;
use App\Services\TaskData\TaskDataUploadService;
use App\Services\Transcript\Exceptions\TranscriptParseException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TaskDataUploadController extends Controller
{
    public function __construct(
        private readonly TaskDataUploadService $service,
    ) {
    }

    /**
     * Upload a file for task extraction. Returns immediately with upload_id.
     * Processing happens async — poll GET /tasks/uploads/{id} for status.
     */
    public function upload(UploadTaskDataRequest $request): ApiResponse
    {
        try {
            $upload = $this->service->handle($request, $request->user());

            return ApiResponse::success(
                message: 'Upload queued for processing',
                data: [
                    'upload_id' => $upload->id,
                    'status'    => $upload->status,
                ],
                status: 202,
            );
        } catch (AuthorizationException $e) {
            return ApiResponse::error(message: $e->getMessage() ?: 'Forbidden', status: 403);
        } catch (AppException $e) {
            return ApiResponse::error(
                message: $e->getMessage(),
                data: ['error_code' => $e->getErrorCode() ?: 'APP_ERROR'],
                status: $e->getCode() ?: 422,
            );
        } catch (TranscriptParseException $e) {
            $logId = (string) Str::uuid();
            Log::warning('TaskDataUpload: parse failed', [
                'log_id'      => $logId,
                'uploader_id' => $request->user()?->id,
                'exception'   => $e->getMessage(),
            ]);
            return ApiResponse::error(
                message: 'Could not process uploaded file',
                data: ['error_code' => 'TASK_DATA_PARSE_FAILED', 'log_id' => $logId],
                status: 422,
            );
        }
    }

    /**
     * Poll processing status. Returns current step + results when done.
     */
    public function status(Request $request, int $uploadId): ApiResponse
    {
        // Ownership gate (IDOR fix): only return uploads the user may see.
        // Response shape unchanged (raw status) so the form's polling is untouched.
        $upload = TaskDataUpload::visibleTo($request->user())->find($uploadId);

        if (!$upload) {
            return ApiResponse::notFound();
        }

        $data = [
            'upload_id'      => $upload->id,
            'status'         => $upload->status,
            'original_filename' => $upload->original_filename,
            'issues_created' => $upload->issues_created,
            'issues_updated' => $upload->issues_updated,
        ];

        if ($upload->status === 'done') {
            // Re-filter issue names through Issue visibility (defense-in-depth, matches
            // UploadLogController::visibleCreatedIssues): the upload-row gate has an
            // ungated own-uploader clause, so a user who left the org/team must not get
            // issue names back here either.
            $issues = Issue::where('sourceable_type', TaskDataUpload::class)
                ->where('sourceable_id', $upload->id)
                ->visibleTo($request->user())
                ->get(['id', 'name'])
                ->map(fn ($issue) => [
                    'id'     => $issue->id,
                    'name'   => $issue->name,
                    'status' => 'new',
                ]);

            $data['issues'] = $issues;

            // Updated issues keep their original sourceable, so they're found by the
            // snapshot of ids stored at processing time (not the morph). Same visibility
            // re-filter as created issues.
            $updatedIds = $upload->updated_issue_ids ?? [];
            $data['updated_issues'] = $updatedIds === []
                ? collect()
                : Issue::whereIn('id', $updatedIds)
                    ->visibleTo($request->user())
                    ->get(['id', 'name'])
                    ->map(fn ($issue) => [
                        'id'     => $issue->id,
                        'name'   => $issue->name,
                        'status' => 'updated',
                    ]);
        }

        return ApiResponse::success(data: $data);
    }
}
