<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'calendar_event_id' => $this->calendar_event_id,
            'status'            => $this->status,
            'title'             => $this->title,
            'summary'           => $this->summary,
            'key_points'        => $this->key_points ?? [],
            'decisions'         => $this->decisions ?? [],
            'attendees'         => $this->calendarEvent->participants->map(fn ($p) => ['name' => $p->name])->values(),
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}
