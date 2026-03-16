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
            'status' => $this->status,
            'content' => $this->content,
            'followup_data' => $this->followup_data,
            'error_message' => $this->error_message,
            'agent_run_uuid' => $this->agent_run_uuid,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
        ];
    }
}
