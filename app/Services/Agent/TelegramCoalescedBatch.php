<?php

namespace App\Services\Agent;

use App\Models\TelegramChatMessage;
use App\Models\TelegramUser;
use Illuminate\Support\Collection;

class TelegramCoalescedBatch
{
    /**
     * @param  Collection<int, TelegramChatMessage>  $messages
     */
    public function __construct(
        public readonly string $batchUuid,
        public readonly int $chatId,
        public readonly TelegramUser $telegramUser,
        public readonly Collection $messages,
        public readonly string $content,
        public readonly ?int $messageThreadId = null,
    ) {}
}
