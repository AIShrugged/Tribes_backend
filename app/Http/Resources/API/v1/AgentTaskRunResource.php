<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentTaskRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent_task_id' => $this->agent_task_id,
            'status' => $this->status?->value ?? $this->status,
            'attempt' => $this->attempt,
            'scheduled_for' => $this->scheduled_for,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'output' => $this->output,
            'error_message' => $this->error_message,
            'metadata' => [
                'sandbox_result' => data_get($this->metadata, 'sandbox_result'),
                'sandbox' => data_get($this->metadata, 'sandbox'),
                'tool_calls' => data_get($this->metadata, 'tool_calls', []),
                'llm_calls' => data_get($this->metadata, 'llm_calls', []),
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
