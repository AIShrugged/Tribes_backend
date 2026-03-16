<?php

namespace App\Services\Agent;

use App\Services\Channel\ChannelBus;
use Illuminate\Support\Facades\Cache;

class TelegramMessageCoalescer
{
    public function __construct(
        private readonly ChannelBus $channelBus,
    ) {}

    public function claimPendingBatch(int $chatId, ?int $messageThreadId = null): ?TelegramCoalescedBatch
    {
        $lock = Cache::lock($this->lockKey($chatId, $messageThreadId), 15);

        if (! $lock->get()) {
            return null;
        }

        try {
            $batch = $this->channelBus->claimTelegramPendingBatch($chatId, $messageThreadId);

            if (! $batch || ! $batch['author_identity']) {
                return null;
            }

            return new TelegramCoalescedBatch(
                batchUuid: $batch['batch_uuid'],
                chatId: $batch['chat_id'],
                authorIdentity: $batch['author_identity'],
                participants: $batch['participants'],
                messages: $batch['messages'],
                content: $batch['content'],
                messageThreadId: $batch['message_thread_id'],
            );
        } finally {
            $lock->release();
        }
    }

    public function markBatchResponded(string $batchUuid): void
    {
        $this->channelBus->markTelegramBatchResponded($batchUuid);
    }

    public function releaseBatch(string $batchUuid): void
    {
        $this->channelBus->releaseTelegramBatch($batchUuid);
    }

    private function lockKey(int $chatId, ?int $messageThreadId): string
    {
        return sprintf('telegram_coalesce:%s:%s', $chatId, $messageThreadId ?? 'root');
    }
}
