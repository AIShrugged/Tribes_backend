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
            'id'                => $this->id,
            'calendar_event_id' => $this->calendar_event_id,
            'participant_id'    => $this->participant_id,
            'scope'             => $this->scope,
            'type'              => $this->type,
            'text'              => $this->text,
            'status'            => $this->status,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}
