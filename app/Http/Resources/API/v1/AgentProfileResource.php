<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'system_prompt' => $this->system_prompt,
            'config_schema' => $this->config_schema,
            'task_payload_schema' => $this->task_payload_schema,
            'execution_mode' => $this->execution_mode?->value,
            'sandbox_profile' => $this->sandbox_profile,
            'allowed_tools' => $this->allowed_tools,
            'allowed_outbound_hosts' => $this->allowed_outbound_hosts,
            'default_model' => $this->default_model,
            'enabled' => $this->enabled,
            'metadata' => $this->metadata,
            'version' => $this->version,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
