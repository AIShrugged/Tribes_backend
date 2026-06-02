<?php

namespace App\Http\Resources\API\v1;

use App\Support\UploadStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Per-upload detail for a task-data upload. Pure presentation: the controller builds
 * the `issues` array (created issues only, re-filtered through Issue::scopeVisibleTo
 * for cross-team name protection) and sets it on the public property.
 *
 * `raw_status` is exposed here (task-data only) to drive the processing timeline UI;
 * `issues_updated` is a count with no drill-through (no link from an upload to the
 * issues it merely updated — kept honest per the contract).
 *
 * @mixin \App\Models\TaskDataUpload
 */
class TaskDataUploadDetailResource extends JsonResource
{
    /** Created issues, re-filtered for visibility by the controller; empty unless done. */
    public array $issues = [];

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
            'uploader_name'     => $this->user?->name,
            'team_name'         => $this->team?->name,
            'created_at'        => $this->created_at?->toISOString(),
            'updated_at'        => $this->updated_at?->toISOString(),
        ];
    }
}
