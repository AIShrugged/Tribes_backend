<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatRunStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->statusEnum();

        return [
            'agent_run_uuid' => $this->agent_run_uuid,
            'chat_id' => $this->chat_id,
            'message_id' => $this->id,
            'status' => $status->value,
            'progress_percent' => $status->progressPercent(),
            'current_step_label' => $status->currentStepLabel(),
            'error_message' => $this->error_message,
            'failure_code' => $this->failure_code,
            'current_attempt' => $this->current_attempt,
            'max_attempts' => $this->max_attempts,
            'completed_at' => $this->completed_at,
            'next_retry_at' => $this->next_retry_at,
            'message' => [
                'id' => $this->id,
                'role' => $this->role,
                'content' => $this->content,
                'created_at' => $this->created_at,
            ],
        ];
    }
}
