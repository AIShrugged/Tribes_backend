<?php

namespace App\Http\Resources\API\v1;

use App\Models\AgendaTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgendaTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'team_id'            => $this->team_id,
            'sections'           => $this->sections,
            'available_sections' => AgendaTemplate::DEFAULT_SECTIONS,
            'created_at'         => $this->created_at,
            'updated_at'         => $this->updated_at,
        ];
    }
}
