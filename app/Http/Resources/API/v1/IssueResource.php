<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'organization_id' => $this->organization_id,
            'team_id' => $this->team_id,
            'sourceable_type' => $this->sourceable_type,
            'sourceable_id' => $this->sourceable_id,
            'assignee_id' => $this->assignee_id,
            'assignee' => $this->whenLoaded('assignee', fn () => UserResource::make($this->assignee)),
            'registration_date' => $this->registration_date,
            'close_date' => $this->close_date,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
