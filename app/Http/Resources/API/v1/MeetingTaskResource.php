<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'sourceable_type' => $this->sourceable_type,
            'sourceable_id'   => $this->sourceable_id,
            'name'          => $this->name,
            'description'   => $this->description,
            'assignee_id'   => $this->assignee_id,
            'assignee_name' => $this->assignee_name,
            'due_date'      => $this->due_date?->toDateString(),
            'status'        => $this->status,
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
        ];
    }
}
