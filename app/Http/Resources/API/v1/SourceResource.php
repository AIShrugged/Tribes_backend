<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SourceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'user_id'         => $this->user_id,
            'organization_id' => $this->organization_id,
            'external_id'     => $this->external_id,
            'identity'        => $this->identity,
            'type'            => $this->type,
            'auth_type'       => $this->auth_type,
            'is_connected'    => $this->is_connected,
            'detached_at'     => $this->deleted_at?->toIso8601String(),
        ];
    }
}
