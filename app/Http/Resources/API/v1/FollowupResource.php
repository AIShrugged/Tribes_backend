<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowupResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'calendar_event' => CalendarEventResource::make($this->calendarEvent),
            'team_id'        => $this->team_id,
            'user'           => UserResource::make($this->user),
            'methodology_id' => $this->methodology_id,
            'text'           => $this->text,
            'status'         => $this->status,
            'created_at'     => $this->created_at,
            'updated_at'     => $this->updated_at,
        ];
    }
}
