<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TelegramChatRegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $topicTitle = $this->message_thread_id !== null
            ? ($this->conversation?->title ?: 'Topic #'.$this->message_thread_id)
            : null;

        return [
            'id' => $this->id,
            'channel_conversation_id' => $this->channel_conversation_id,
            'user_id' => $this->conversation?->user_id,
            'telegram_chat_id' => $this->telegram_chat_id,
            'message_thread_id' => $this->message_thread_id,
            'topic_title' => $topicTitle,
            'topic_label' => $topicTitle,
            'chat_type' => $this->chat_type,
            'chat_title' => $this->chat_title,
            'organization_id' => $this->organization_id,
            'team_id' => $this->team_id,
            'attach_code' => $this->attach_code,
            'attach_command' => $this->attach_code ? '/attach '.$this->attach_code : null,
            'attach_code_expires_at' => $this->attach_code_expires_at,
            'attach_code_used_at' => $this->attach_code_used_at,
            'is_bound' => $this->bound_at !== null,
            'bound_at' => $this->bound_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
