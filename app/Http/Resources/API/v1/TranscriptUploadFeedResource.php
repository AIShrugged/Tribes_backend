<?php

namespace App\Http\Resources\API\v1;

use App\Support\UploadStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the unified Upload Log feed (transcript variant). Emits the SAME key
 * set as {@see TaskDataUploadFeedResource}, null-filling non-applicable fields, so
 * the controller can merge both into one feed. created_at is ISO-8601 so the merged
 * list can be sorted lexicographically (= chronologically).
 *
 * @mixin \App\Models\TranscriptUpload
 */
class TranscriptUploadFeedResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'type'              => 'transcript',
            'original_filename' => $this->original_filename,
            'status'            => UploadStatus::normalize($this->status),
            'error_message'     => $this->error_message,
            'uploader_name'     => $this->user?->name,
            'team_name'         => null, // transcripts are org-scoped
            'created_at'        => $this->created_at?->toISOString(),
            'calendar_event_id' => $this->calendar_event_id,
            'issues_created'    => null,
            'issues_updated'    => null,
        ];
    }
}
