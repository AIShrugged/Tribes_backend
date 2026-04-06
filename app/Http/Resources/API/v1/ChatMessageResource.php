<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chat_id' => $this->chat_id,
            'role' => $this->role,
            'status' => $this->statusValue(),
            'content' => $this->content,
            'followup_data' => $this->followup_data,
            'error_message' => $this->error_message,
            'failure_code' => $this->failure_code,
            'agent_run_uuid' => $this->agent_run_uuid,
            'current_attempt' => $this->current_attempt,
            'max_attempts' => $this->max_attempts,
            'completed_at' => $this->completed_at,
            'next_retry_at' => $this->next_retry_at,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
        ];
    }
}
