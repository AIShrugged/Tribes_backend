<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MethodologyResource extends JsonResource
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
            'name'           => $this->name,
            'text'           => $this->text,
            'scheme'         => $this->scheme,
            'scheme_version' => $this->scheme_version,
            'is_default'     => $this->is_default,
            'teams'          => TeamResource::collection($this->teams),
            'organization_id' => $this->organization_id
        ];
    }
}
