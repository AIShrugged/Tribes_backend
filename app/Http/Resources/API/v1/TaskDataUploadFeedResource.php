<?php

namespace App\Http\Resources\API\v1;

use App\Support\UploadStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the unified Upload Log feed (task-data variant). Mirrors the key set
 * of {@see TranscriptUploadFeedResource}.
 *
 * @mixin \App\Models\TaskDataUpload
 */
class TaskDataUploadFeedResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'type'              => 'task_data',
            'original_filename' => $this->original_filename,
            'status'            => UploadStatus::normalize($this->status),
            'error_message'     => $this->error_message,
            'uploader_name'     => $this->user?->name,
            'team_name'         => $this->team?->name,
            'created_at'        => $this->created_at?->toISOString(),
            'calendar_event_id' => null,
            'issues_created'    => $this->issues_created,
            'issues_updated'    => $this->issues_updated,
        ];
    }
}
