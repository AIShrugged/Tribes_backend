<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingSummaryTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'team_id'          => $this->team_id,
            'sections'         => $this->sections,
            'visible_sections' => $this->visible_sections,
            'prompt_override'  => $this->prompt_override,
            'version'          => $this->version,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }
}
