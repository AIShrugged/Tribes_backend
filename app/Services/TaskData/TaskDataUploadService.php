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
        $team = $this->resolveTeam($request, $uploader);

        $file = $request->file('file');

        $upload = TaskDataUpload::create([
            'user_id'           => $uploader->id,
            'team_id'           => $team->id,
            'organization_id'   => $team->organization_id,
            'original_filename' => $file->getClientOriginalName(),
            'status'            => 'queued',
        ]);

        // Read + extract + normalize here (sync, in the HTTP-serving container).
        // Only the text string crosses the queue boundary — no filesystem dependency.
        $rawContent = $this->archiveExtractor->extract($file);
        $content = $this->normalizer->normalize($rawContent);

        ProcessTaskDataUploadJob::dispatch($upload->id, $content);

        return $upload;
    }

    private function resolveTeam(UploadTaskDataRequest $request, User $uploader): Team
    {
        $team = Team::findOrFail($request->teamId());

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
