<?php

namespace App\Services\Agent;

use App\Models\ChannelIdentity;
use App\Models\ChannelMessage;
use Illuminate\Support\Collection;

class TelegramCoalescedBatch
{
    /**
     * @param  Collection<int, ChannelMessage>  $messages
     */
    public function __construct(
        public readonly string $batchUuid,
        public readonly int $chatId,
        public readonly ChannelIdentity $authorIdentity,
        public readonly Collection $participants,
        public readonly Collection $messages,
        public readonly string $content,
        public readonly ?int $messageThreadId = null,
    ) {}
}
