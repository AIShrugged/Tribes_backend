<?php

namespace App\Http\Resources\API\v1;

use App\Support\UploadStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Per-upload detail for a task-data upload. Pure presentation: the controller builds
 * the `issues` (created) and `updated_issues` arrays — each re-filtered through
 * Issue::scopeVisibleTo for cross-team name protection — and sets them on the public
 * properties.
 *
 * `raw_status` is exposed here (task-data only) to drive the processing timeline UI.
 * `issues_created`/`issues_updated` are the recorded counts; the matching lists below
 * may be shorter when an issue was deleted or is no longer visible to the viewer.
 *
 * @mixin \App\Models\TaskDataUpload
 */
class TaskDataUploadDetailResource extends JsonResource
{
    /** Created issues, re-filtered for visibility by the controller; empty unless done. */
    public array $issues = [];

    /** Issues this upload merely updated, re-filtered for visibility; empty unless done. */
    public array $issuesUpdated = [];

    /** Staged moderation plan; set by the controller only when status normalizes to 'review'. */
    public ?array $plan = null;

    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'type'              => 'task_data',
            'original_filename' => $this->original_filename,
            'status'            => UploadStatus::normalize($this->status),
            'raw_status'        => $this->status,
            'error_message'     => $this->error_message,
            'issues_created'    => $this->issues_created,
            'issues_updated'    => $this->issues_updated,
            'issues'            => $this->issues,
            'updated_issues'    => $this->issuesUpdated,
            'plan'              => $this->plan,
            'uploader_name'     => $this->user?->name,
            'team_name'         => $this->team?->name,
            'created_at'        => $this->created_at?->toISOString(),
            'updated_at'        => $this->updated_at?->toISOString(),
        ];
    }
}
