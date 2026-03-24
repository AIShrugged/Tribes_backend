<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalendarEventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'platform'     => $this->platform,
            'url'          => $this->url,
            'title'        => $this->title,
            'description'  => $this->description,
            'starts_at'    => $this->starts_at,
            'ends_at'      => $this->ends_at,
            'external_id'  => $this->external_id,
            'source_id'    => $this->source_id,
            'required_bot' => $this->required_bot,
            'has_summary'  => $this->meetingSummary !== null
                && $this->meetingSummary->status !== 'in_progress',
        ];
    }
}
