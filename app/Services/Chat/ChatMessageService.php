<?php

namespace App\Services\Chat;

use App\Enums\ChatRunStatus;
use App\Models\Chat;
use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

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

    public function findAssistantRun(Chat $chat, string $runUuid): ?ChatMessage
    {
        if (! Str::isUuid($runUuid)) {
            return null;
        }

        return $chat->messages()
            ->where('role', 'assistant')
            ->where('agent_run_uuid', $runUuid)
            ->first();
    }

    public function createUserMessage(Chat $chat, string $content): ChatMessage
    {
        return $this->create($chat, 'user', $content);
    }

    public function createAssistantMessage(Chat $chat, string $content, ?array $followupData = null): ChatMessage
    {
        return $this->create($chat, 'assistant', $content, $followupData);
    }

    public function createQueuedAssistantMessage(Chat $chat, string $content = 'Processing...'): ChatMessage
    {
        return $this->create($chat, 'assistant', $content, null, [
            'status' => ChatRunStatus::QUEUED->value,
            'agent_run_uuid' => (string) Str::uuid(),
            'current_attempt' => 0,
            'max_attempts' => (int) config('agent.chat.max_attempts', 3),
        ]);
    }

    private function create(Chat $chat, string $role, string $content, ?array $followupData = null, array $attributes = []): ChatMessage
    {
        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'role' => $role,
            'status' => ChatRunStatus::COMPLETED->value,
            'content' => $content,
            'followup_data' => $followupData,
            ...$attributes,
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
