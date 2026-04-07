<?php

namespace App\Services\Chat;

use App\Models\ChannelMessage;
use App\Models\Chat;
use App\Models\User;
use App\Services\Channel\ChannelRuntimeService;

class TribesBotService
{
    public function __construct(
        private readonly ChannelRuntimeService $runtimeService,
    ) {}

    public function processMessage(User $user, Chat $chat, string $content, array $pageContext = []): ChannelMessage
    {
        return $this->runtimeService->queueWebChatRun($user, $chat, $content, $pageContext);
    }
}
