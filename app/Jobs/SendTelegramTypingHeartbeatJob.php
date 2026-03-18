<?php

namespace App\Jobs;

use App\Services\Channel\TelegramTypingIndicator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendTelegramTypingHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $sessionId,
    ) {}

    public function handle(TelegramTypingIndicator $typingIndicator): void
    {
        $payload = $typingIndicator->get($this->sessionId);

        if ($payload === null) {
            return;
        }

        $typingIndicator->sendTyping(
            (int) $payload['chat_id'],
            isset($payload['message_thread_id']) ? (int) $payload['message_thread_id'] : null,
        );

        if (config('queue.default') === 'sync') {
            return;
        }

        self::dispatch($this->sessionId)
            ->delay(now()->addSeconds($typingIndicator->intervalSeconds()));
    }
}
