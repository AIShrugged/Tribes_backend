<?php

namespace App\Http\Resources\API\v1;

use App\Support\UploadStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Per-upload detail for a transcript upload. Pure presentation: the controller
 * derives the live `processing` block (via TranscriptUploadDetailService) and sets
 * it on the public property before returning, so this resource issues no queries.
 *
 * No `raw_status`: transcript processing is synchronous from the client's view, so
 * the row is only ever observed as done/failed — normalized status + error_message
 * fully drive the view.
 *
 * @mixin \App\Models\TranscriptUpload
 */
class TranscriptUploadDetailResource extends JsonResource
{
    /** Derived live by the controller when calendar_event_id is set; null otherwise. */
    public ?array $processing = null;

    /** Staged moderation plan; set by the controller only when status normalizes to 'review'. */
    public ?array $plan = null;

    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->id,
            'type'                     => 'transcript',
            'original_filename'        => $this->original_filename,
            'status'                   => UploadStatus::normalize($this->status),
            'error_message'            => $this->error_message,
            'calendar_event_id'        => $this->calendar_event_id,
            'calendar_event_date'      => $this->calendarEvent?->starts_at?->toDateString(),
            'transcript_entries_count' => $this->transcript_entries_count,
            'participants_count'       => $this->participants_count,
            'processing'               => $this->processing,
            'plan'                     => $this->plan,
            'uploader_name'            => $this->user?->name,
            'created_at'               => $this->created_at?->toISOString(),
            'updated_at'               => $this->updated_at?->toISOString(),
        ];
    }
}
