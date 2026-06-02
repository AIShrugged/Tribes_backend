<?php

namespace App\Services\TaskData;

use App\Exceptions\AppException;
use App\Http\Requests\API\v1\UploadTaskDataRequest;
use App\Jobs\ProcessTaskDataUploadJob;
use App\Models\TaskDataUpload;
use App\Models\Team;
use App\Models\User;
use App\Services\Transcript\TranscriptArchiveExtractor;
use App\Services\Transcript\TranscriptContentNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class TaskDataUploadService
{
    public function __construct(
        private readonly TranscriptArchiveExtractor $archiveExtractor,
        private readonly TranscriptContentNormalizer $normalizer,
    ) {
    }

    /**
     * Create upload record, read file content immediately (same container),
     * dispatch async processing job with the content string.
     *
     * File is read here (not in the job) because backend and queue run in
     * separate containers with separate filesystems on prod/staging.
     */
    public function handle(UploadTaskDataRequest $request, User $uploader): TaskDataUpload
    {
        return $this->handleFile(
            $request->file('file'),
            $uploader,
            $request->teamId(),
        );
    }

    public function handleFile(
        UploadedFile $file,
        User $uploader,
        int $teamId,
        ?int $sourceTelegramChatId = null,
        ?int $sourceTelegramThreadId = null,
    ): TaskDataUpload {
        $team = $this->resolveTeam($teamId, $uploader);
        $upload = TaskDataUpload::create([
            'user_id'           => $uploader->id,
            'team_id'           => $team->id,
            'organization_id'   => $team->organization_id,
            // Strip control chars + cap at the varchar(255) column so a long/hostile
            // filename produces a valid row instead of a 500 at insert.
            'original_filename' => Str::limit(
                preg_replace('/[\x00-\x1F]/', '', $file->getClientOriginalName() ?? 'upload'),
                255,
                '',
            ),
            'source_telegram_chat_id' => $sourceTelegramChatId,
            'source_telegram_thread_id' => $sourceTelegramThreadId,
            'status'            => 'queued',
        ]);

        // Read + extract + normalize here (sync, in the HTTP-serving container).
        // Only the text string crosses the queue boundary — no filesystem dependency.
        // A failure here happens AFTER the row exists — mark it failed (else it stays
        // stuck in 'queued' forever, rendering as 'processing') before re-throwing so
        // the controller still returns its 422.
        try {
            $rawContent = $this->archiveExtractor->extract($file);
            $content = $this->normalizer->normalize($rawContent);
        } catch (\Throwable $e) {
            $upload->update([
                'status'        => 'failed',
                'error_message' => 'Could not process uploaded file',
            ]);

            throw $e;
        }

        ProcessTaskDataUploadJob::dispatch($upload->id, $content);

        return $upload;
    }

    private function resolveTeam(int $teamId, User $uploader): Team
    {
        $team = Team::findOrFail($teamId);

        $uploaderOrgIds = $uploader->organizations()->pluck('organizations.id');
        if (!$uploaderOrgIds->contains($team->organization_id)) {
            throw new AppException(
                'Selected team is not in your organization',
                'TEAM_ORG_MISMATCH',
                403,
            );
        }

        return $team;
    }
}
