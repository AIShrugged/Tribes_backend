<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LlmPromptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'slug' => $this->slug,
            'name' => $this->name,
            'prompt' => $this->prompt,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
