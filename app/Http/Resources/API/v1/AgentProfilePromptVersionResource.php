<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentProfilePromptVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'agent_profile_id' => $this->agent_profile_id,
            'version'          => $this->version,
            'system_prompt'    => $this->system_prompt,
            'created_at'       => $this->created_at,
        ];
    }
}
