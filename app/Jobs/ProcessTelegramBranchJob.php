<?php

namespace App\Jobs;

use App\Services\Agent\TelegramMessageCoalescer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessTelegramBranchJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $chatId,
        public ?int $messageThreadId = null,
    ) {}

    public function handle(TelegramMessageCoalescer $coalescer): void
    {
        $batch = $coalescer->claimPendingBatch($this->chatId, $this->messageThreadId);

        if (! $batch || ! $batch->telegramUser->user) {
            return;
        }

        ProcessTelegramWorkerJob::dispatch(
            $batch->chatId,
            $batch->telegramUser->telegram_user_id,
            $batch->telegramUser->user->id,
            $batch->batchUuid,
            $batch->content,
            $batch->messageThreadId,
        );
    }
}
