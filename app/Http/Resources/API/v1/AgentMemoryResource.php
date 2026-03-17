<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentMemoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->whenLoaded('profile');
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        return [
            'id' => $this->id,
            'agent_profile_id' => $this->agent_profile_id,
            'profile' => $profile ? [
                'id' => $profile->id,
                'key' => $profile->key,
                'name' => $profile->name,
            ] : null,
            'scope_type' => $this->scope_type,
            'scope_key' => $this->scope_key,
            'kind' => $this->kind,
            'priority' => $this->priority,
            'active' => $this->active,
            'content' => $this->content,
            'last_seen_at' => $this->last_seen_at,
            'source_task_id' => $metadata['source_task_id'] ?? null,
            'source_run_id' => $metadata['source_run_id'] ?? null,
            'metadata' => $metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
