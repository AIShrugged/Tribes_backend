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
            'id'               => $this->id,
            'platform'         => $this->platform,
            'url'              => $this->url,
            'title'            => $this->title,
            'description'      => $this->description,
            'starts_at'        => $this->starts_at,
            'ends_at'          => $this->ends_at,
            'creator_user_id'  => $this->creator_user_id,
            'required_bot'     => $this->isRequiredBot(),
            'organization_id'  => $this->botOrganizationId(),
            'has_summary'      => $this->meetingSummary !== null
                && $this->meetingSummary->status !== 'in_progress',
        ];
    }
}
