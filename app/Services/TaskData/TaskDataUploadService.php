<?php

namespace App\Services\TaskData;

use App\Exceptions\AppException;
use App\Http\Requests\API\v1\UploadTaskDataRequest;
use App\Jobs\ProcessTaskDataUploadJob;
use App\Models\TaskDataUpload;
use App\Models\Team;
use App\Models\User;

class TaskDataUploadService
{
    /**
     * Create upload record, persist file to temp, dispatch async processing job.
     * Returns immediately — caller gets upload_id for polling.
     */
    public function handle(UploadTaskDataRequest $request, User $uploader): TaskDataUpload
    {
        $team = $this->resolveTeam($request, $uploader);

        $file = $request->file('file');
        $tempPath = $file->store('task-data-uploads', 'local');
        $fullPath = storage_path('app/' . $tempPath);

        $upload = TaskDataUpload::create([
            'user_id'           => $uploader->id,
            'team_id'           => $team->id,
            'organization_id'   => $team->organization_id,
            'original_filename' => $file->getClientOriginalName(),
            'status'            => 'queued',
        ]);

        ProcessTaskDataUploadJob::dispatch($upload->id, $fullPath);

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
