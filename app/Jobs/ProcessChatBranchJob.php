<?php

namespace App\Jobs;

use App\Services\Channel\ChannelRuntimeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessChatBranchJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $chatId,
        public int $userId,
        public int $userMessageId,
        public int $assistantMessageId,
    ) {}

    public function handle(ChannelRuntimeService $runtimeService): void
    {
        $runtimeService->handleChatBranch(
            $this->chatId,
            $this->userId,
            $this->userMessageId,
            $this->assistantMessageId,
        );
    }
}
