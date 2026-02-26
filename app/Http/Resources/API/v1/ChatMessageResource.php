<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'chat_id'       => $this->conversation_id, // backward compat: conversation_id exposed as chat_id
            'role'          => $this->role,
            'content'       => $this->content,
            'followup_data' => $this->getFollowupData(),
            'created_at'    => $this->created_at,
        ];
    }
}
