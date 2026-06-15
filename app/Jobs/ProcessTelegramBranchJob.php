<?php

namespace App\Jobs;

use App\Services\Channel\ChannelRuntimeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessTelegramBranchJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $chatId,
        public ?int $messageThreadId = null,
    ) {
        $this->onQueue('chat');
    }

    public function handle(ChannelRuntimeService $runtimeService): void
    {
        $runtimeService->handleTelegramBranch($this->chatId, $this->messageThreadId);
    }
}
