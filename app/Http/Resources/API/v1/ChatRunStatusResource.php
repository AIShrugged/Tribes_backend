<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatRunStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'agent_run_uuid' => $this->agent_run_uuid,
            'chat_id' => $this->chat_id,
            'message_id' => $this->id,
            'status' => $this->status,
            'progress_percent' => $this->progressPercent(),
            'current_step_label' => $this->currentStepLabel(),
            'error_message' => $this->error_message,
            'completed_at' => $this->completed_at,
            'message' => [
                'id' => $this->id,
                'role' => $this->role,
                'content' => $this->content,
                'created_at' => $this->created_at,
            ],
        ];
    }

    private function progressPercent(): int
    {
        return match ($this->status) {
            'queued' => 5,
            'processing' => 50,
            'completed' => 100,
            'failed' => 100,
            default => 0,
        };
    }

    private function currentStepLabel(): ?string
    {
        return match ($this->status) {
            'queued' => 'Queued',
            'processing' => 'Generating response',
            'completed' => 'Completed',
            'failed' => null,
            default => null,
        };
    }
}
