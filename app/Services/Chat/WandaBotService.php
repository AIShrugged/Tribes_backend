<?php

namespace App\Services\Chat;

use App\Jobs\ProcessChatBranchJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;

class WandaBotService
{
    public function __construct(
        private readonly ChatMessageService $messageService,
    ) {}

    public function processMessage(User $user, Chat $chat, string $content): ChatMessage
    {
        $userMessage = $this->messageService->createUserMessage($chat, $content);
        $assistantMessage = $this->messageService->createQueuedAssistantMessage($chat);

        ProcessChatBranchJob::dispatch(
            $chat->id,
            $user->id,
            $userMessage->id,
            $assistantMessage->id,
        );

        return $assistantMessage;
    }
}
