<?php

namespace App\Services\Agent;

use App\Models\TelegramChatMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramMessageCoalescer
{
    public function claimPendingBatch(int $chatId, ?int $messageThreadId = null): ?TelegramCoalescedBatch
    {
        $lock = Cache::lock($this->lockKey($chatId, $messageThreadId), 15);

        if (! $lock->get()) {
            return null;
        }

        try {
            return DB::transaction(function () use ($chatId, $messageThreadId) {
                $query = TelegramChatMessage::query()
                    ->where('telegram_chat_id', $chatId)
                    ->where('role', 'user')
                    ->whereNull('coalesced_at')
                    ->whereNull('responded_at')
                    ->orderBy('id');

                if ($messageThreadId === null) {
                    $query->whereNull('message_thread_id');
                } else {
                    $query->where('message_thread_id', $messageThreadId);
                }

                $messages = $query->lockForUpdate()->get();

                if ($messages->isEmpty()) {
                    return null;
                }

                $batchUuid = (string) Str::uuid();
                $now = now();

                TelegramChatMessage::query()
                    ->whereIn('id', $messages->pluck('id'))
                    ->update([
                        'agent_batch_uuid' => $batchUuid,
                        'coalesced_at' => $now,
                    ]);

                $messages->each(function (TelegramChatMessage $message) use ($batchUuid, $now) {
                    $message->agent_batch_uuid = $batchUuid;
                    $message->coalesced_at = $now;
                });

                $content = $messages
                    ->pluck('content')
                    ->map(fn (string $text, int $index) => ($index + 1).'. '.trim($text))
                    ->implode("\n");

                return new TelegramCoalescedBatch(
                    batchUuid: $batchUuid,
                    chatId: $chatId,
                    telegramUser: $messages->last()->telegramUser,
                    messages: $messages,
                    content: $content,
                    messageThreadId: $messageThreadId,
                );
            });
        } finally {
            $lock->release();
        }
    }

    public function markBatchResponded(string $batchUuid): void
    {
        TelegramChatMessage::query()
            ->where('agent_batch_uuid', $batchUuid)
            ->update(['responded_at' => now()]);
    }

    public function releaseBatch(string $batchUuid): void
    {
        TelegramChatMessage::query()
            ->where('agent_batch_uuid', $batchUuid)
            ->update([
                'agent_batch_uuid' => null,
                'coalesced_at' => null,
            ]);
    }

    private function lockKey(int $chatId, ?int $messageThreadId): string
    {
        return sprintf('telegram_coalesce:%s:%s', $chatId, $messageThreadId ?? 'root');
    }
}
