<?php

namespace App\Http\Controllers\API\v1;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\UploadTaskDataRequest;
use App\Http\Responses\ApiResponse;
use App\Services\TaskData\TaskDataUploadService;
use App\Services\Transcript\Exceptions\TranscriptParseException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TaskDataUploadController extends Controller
{
    public function __construct(
        private readonly TaskDataUploadService $service,
    ) {
    }

    public function upload(UploadTaskDataRequest $request): ApiResponse
    {
        try {
            $result = $this->service->handle($request, $request->user());

            $issues = $result['created']->merge($result['updated'])->map(fn ($issue) => [
                'id'     => $issue->id,
                'name'   => $issue->name,
                'status' => $result['created']->contains('id', $issue->id) ? 'new' : 'updated',
            ])->values();

            return ApiResponse::success(
                message: 'Task data processed',
                data: [
                    'task_data_upload_id' => $result['upload']->id,
                    'issues_created'      => $result['created']->count(),
                    'issues_updated'      => $result['updated']->count(),
                    'issues'              => $issues,
                ],
                status: 201,
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
}
