<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class MessageService
{
    public function getMessages(Conversation $conversation, int $offset = 0, int $limit = 50): Collection
    {
        return $conversation->messages()
            ->orderBy('created_at')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    public function countMessages(Conversation $conversation): int
    {
        return $conversation->messages()->count();
    }

    public function createUserMessage(Conversation $conversation, Model $sender, string $content): Message
    {
        return $this->create($conversation, 'user', $content, $sender);
    }

    public function createAssistantMessage(Conversation $conversation, string $content, ?array $followupData = null): Message
    {
        $metadata = $followupData ? ['followup_data' => $followupData] : [];

        return $this->create($conversation, 'assistant', $content, null, $metadata);
    }

    public function getRecentHistory(Conversation $conversation, int $limit = 20): Collection
    {
        return $conversation->messages()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    private function create(
        Conversation $conversation,
        string $role,
        string $content,
        ?Model $sender = null,
        array $metadata = [],
    ): Message {
        $message = $conversation->messages()->create([
            'role'        => $role,
            'content'     => $content,
            'sender_type' => $sender?->getMorphClass(),
            'sender_id'   => $sender?->getKey(),
            'metadata'    => $metadata,
        ]);

        $conversation->touch();

        return $message;
    }
}
