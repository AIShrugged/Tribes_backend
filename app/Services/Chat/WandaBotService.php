<?php

namespace App\Services\Chat;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Chat\Handlers\ReportHandler;

class WandaBotService
{
    public function __construct(
        private readonly ChatMessageService $messageService,
        private readonly ReportHandler $reportHandler,
    ) {
    }

    public function processMessage(User $user, Chat $chat, string $content): ChatMessage
    {
        $this->messageService->createUserMessage($chat, $content);

        return $this->route($user, $chat, $content);
    }

    private function route(User $user, Chat $chat, string $content): ChatMessage
    {
        // TODO: определение намерения — profiling, goals, advice, etc.
        return $this->reportHandler->handle($user, $chat, $content);
    }
}
