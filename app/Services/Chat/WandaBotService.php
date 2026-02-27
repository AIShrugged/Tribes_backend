<?php

namespace App\Services\Chat;

use App\Enums\OutputMode;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Agent\AgentService;

class WandaBotService
{
    public function __construct(
        private readonly ChatMessageService $messageService,
        private readonly AgentService $agentService,
    ) {
    }

    public function processMessage(User $user, Chat $chat, string $content): ChatMessage
    {
        // Get history before saving the current user message so it's not included in context
        $history = $this->messageService->getRecentHistory($chat);

        $this->messageService->createUserMessage($chat, $content);

        $this->agentService->registerChatTools($chat);

        $responseText = $this->agentService->processMessage($user, $history, $content, null, OutputMode::MD);

        return $this->messageService->createAssistantMessage($chat, $responseText);
    }
}
