<?php

namespace App\Services\Chat;

use App\Enums\OutputMode;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Agent\AgentService;

class WandaBotService
{
    public function __construct(
        private readonly MessageService $messageService,
        private readonly AgentService $agentService,
    ) {
    }

    public function processMessage(User $user, Conversation $conversation, string $content): Message
    {
        // Get history before saving the current user message so it's not included in context
        $history = $this->messageService->getRecentHistory($conversation);

        $this->messageService->createUserMessage($conversation, $user, $content);

        $this->agentService->registerChatTools($chat);

        $responseText = $this->agentService->processMessage($user, $history, $content, null, OutputMode::MD);

        return $this->messageService->createAssistantMessage($conversation, $responseText);
    }
}
