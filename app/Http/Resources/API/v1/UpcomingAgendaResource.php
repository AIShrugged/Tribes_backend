<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UpcomingAgendaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                        => $this->id,
            'status'                    => $this->status,
            'content'                   => $this->content,
            'raw_json'                  => $this->raw_json,
            'source_meeting_title'      => $this->sourceCalendarEvent->title,
            'source_meeting_date'       => $this->sourceCalendarEvent->starts_at,
            'source_meeting_id'         => $this->source_calendar_event_id,
            'source_meeting_participants' => $this->sourceCalendarEvent->participants
                ->pluck('name')
                ->filter()
                ->values(),
            'created_at'                => $this->created_at,
            'updated_at'                => $this->updated_at,
        ];
    }
}
