<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'calendar_event_id' => $this->calendar_event_id,
            'profile_id'        => $this->profile_id,
            'title'             => $this->title,
            'description'       => $this->description,
            'assignee_name'     => $this->assignee_name,
            'due_date'          => $this->due_date?->toDateString(),
            'status'            => $this->status,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}
