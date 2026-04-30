<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingKeyPointResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'text'            => $this->text,
            'position'        => $this->position,
            'team_id'         => $this->team_id,
            'organization_id' => $this->organization_id,
            'calendar_event'  => $this->whenLoaded('calendarEvent', fn () => [
                'id'        => $this->calendarEvent->id,
                'title'     => $this->calendarEvent->title,
                'starts_at' => $this->calendarEvent->starts_at,
            ]),
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }
}