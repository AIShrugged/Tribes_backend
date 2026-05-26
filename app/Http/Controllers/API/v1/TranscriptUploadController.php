<?php

namespace App\Http\Controllers\API\v1;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\UploadTranscriptRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Transcript\Exceptions\TooManyEntriesException;
use App\Services\Transcript\Exceptions\TranscriptParseException;
use App\Services\Transcript\Exceptions\UnsupportedFormatException;
use App\Services\Transcript\TranscriptUploadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TranscriptUploadController extends Controller
{
    public function __construct(
        private readonly TranscriptUploadService $service,
    ) {
    }

    public function upload(UploadTranscriptRequest $request): ApiResponse
    {
        try {
            $result = $this->service->handle($request, $request->user());

            return ApiResponse::success(
                message: 'Transcript uploaded',
                data: [
                    'calendar_event_id'        => $result['calendar_event']->id,
                    'transcript_entries_count' => $result['transcript_entries_count'],
                    'participants_count'       => $result['participants_count'],
                ],
                status: 201,
            );
        } catch (AuthorizationException $e) {
            return ApiResponse::error(
                message: $e->getMessage() ?: 'Forbidden',
                status: 403,
            );
        } catch (AppException $e) {
            return ApiResponse::error(
                message: $e->getMessage(),
                data: ['error_code' => $e->getErrorCode() ?: 'APP_ERROR'],
                status: 422,
            );
        } catch (TooManyEntriesException $e) {
            return ApiResponse::error(
                message: 'Transcript exceeds the entry limit',
                data: ['error_code' => 'TOO_MANY_ENTRIES', 'count' => $e->count, 'limit' => $e->limit],
                status: 422,
            );
        } catch (UnsupportedFormatException $e) {
            // Signature detector + LLM fallback both gave up on the file.
            $logId = (string) Str::uuid();
            Log::warning('TranscriptUpload: format unrecognized', [
                'log_id'      => $logId,
                'uploader_id' => $request->user()?->id,
                'exception'   => $e->getMessage(),
            ]);

            return ApiResponse::error(
                message: 'Transcript format not recognized',
                data: ['error_code' => 'TRANSCRIPT_FORMAT_UNRECOGNIZED', 'log_id' => $logId],
                status: 422,
            );
        } catch (TranscriptParseException $e) {
            // Don't leak parser internals (file paths, JSON byte offsets, etc).
            $logId = (string) Str::uuid();
            Log::warning('TranscriptUpload: parse failed', [
                'log_id'      => $logId,
                'uploader_id' => $request->user()?->id,
                'exception'   => $e->getMessage(),
            ]);

            return ApiResponse::error(
                message: 'Could not parse transcript file',
                data: ['error_code' => 'TRANSCRIPT_PARSE_FAILED', 'log_id' => $logId],
                status: 422,
            );
        }
    }
}
