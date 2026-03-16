<?php

namespace App\Jobs;

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

    public function handle(): void
    {
        ProcessChatWorkerJob::dispatch(
            $this->chatId,
            $this->userId,
            $this->userMessageId,
            $this->assistantMessageId,
        );
    }
}
