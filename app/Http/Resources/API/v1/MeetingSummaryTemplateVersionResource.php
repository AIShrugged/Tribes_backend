<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingSummaryTemplateVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'template_id'      => $this->template_id,
            'version'          => $this->version,
            'sections'         => $this->sections,
            'visible_sections' => $this->visible_sections,
            'prompt_override'  => $this->prompt_override,
            'created_at'       => $this->created_at,
        ];
    }
}
