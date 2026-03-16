<?php

namespace App\Services\Chat;

use App\Models\ChannelMessage;
use App\Models\Chat;
use App\Services\Channel\ChannelBus;
use Illuminate\Database\Eloquent\Collection;

class ChatMessageService
{
    public function __construct(
        private readonly ChannelBus $channelBus,
    ) {}

    public function getMessages(Chat $chat, int $offset = 0, int $limit = 50): Collection
    {
        return $this->channelBus->getChatMessages($chat, $offset, $limit);
    }

    public function countMessages(Chat $chat): int
    {
        return $this->channelBus->countChatMessages($chat);
    }

    public function findAssistantRun(Chat $chat, string $runUuid): ?ChannelMessage
    {
        return $this->channelBus->findChatAssistantRun($chat, $runUuid);
    }

    public function createUserMessage(Chat $chat, string $content): ChannelMessage
    {
        return $this->channelBus->createChatUserMessage($chat, $content);
    }

    public function createAssistantMessage(Chat $chat, string $content, ?array $followupData = null): ChannelMessage
    {
        return $this->channelBus->createChatAssistantMessage($chat, $content, $followupData);
    }

    public function createQueuedAssistantMessage(Chat $chat, string $content = 'Processing...'): ChannelMessage
    {
        return $this->channelBus->createQueuedChatAssistantMessage($chat, $content);
    }

    public function getRecentHistory(Chat $chat, int $limit = 20): Collection
    {
        return $this->channelBus->getRecentChatHistory($chat, $limit);
    }
}
