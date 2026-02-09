<?php

namespace App\Services\Chat;

use App\Models\Chat;
use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Collection;

class ChatMessageService
{
    public function getMessages(Chat $chat, int $offset = 0, int $limit = 50): Collection
    {
        return $chat->messages()
            ->orderBy('created_at')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    public function countMessages(Chat $chat): int
    {
        return $chat->messages()->count();
    }

    public function createUserMessage(Chat $chat, string $content): ChatMessage
    {
        return $this->create($chat, 'user', $content);
    }

    public function createAssistantMessage(Chat $chat, string $content, ?array $followupData = null): ChatMessage
    {
        return $this->create($chat, 'assistant', $content, $followupData);
    }

    private function create(Chat $chat, string $role, string $content, ?array $followupData = null): ChatMessage
    {
        $message = ChatMessage::create([
            'chat_id'       => $chat->id,
            'role'          => $role,
            'content'       => $content,
            'followup_data' => $followupData,
        ]);

        $chat->touch();

        return $message;
    }

    public function getRecentHistory(Chat $chat, int $limit = 20): Collection
    {
        return $chat->messages()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }
}
