<?php

namespace App\Services\Channel;

use App\Jobs\SendTelegramTypingHeartbeatJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class TelegramTypingIndicator
{
    public function start(string $sessionId, int $chatId, ?int $messageThreadId = null): void
    {
        Cache::put(
            $this->cacheKey($sessionId),
            [
                'chat_id' => $chatId,
                'message_thread_id' => $messageThreadId,
            ],
            now()->addSeconds($this->ttlSeconds()),
        );

        $this->sendTyping($chatId, $messageThreadId);

        if (config('queue.default') === 'sync') {
            return;
        }

        SendTelegramTypingHeartbeatJob::dispatch($sessionId)
            ->delay(now()->addSeconds($this->intervalSeconds()));
    }

    public function touch(string $sessionId): void
    {
        $payload = $this->get($sessionId);

        if ($payload === null) {
            return;
        }

        Cache::put(
            $this->cacheKey($sessionId),
            $payload,
            now()->addSeconds($this->ttlSeconds()),
        );
    }

    public function stop(string $sessionId): void
    {
        Cache::forget($this->cacheKey($sessionId));
    }

    public function get(string $sessionId): ?array
    {
        $payload = Cache::get($this->cacheKey($sessionId));

        return is_array($payload) ? $payload : null;
    }

    public function sendTyping(int $chatId, ?int $messageThreadId = null): void
    {
        try {
            $params = [
                'chat_id' => $chatId,
                'action' => 'typing',
            ];

            if ($messageThreadId !== null) {
                $params['message_thread_id'] = $messageThreadId;
            }

            (new Api(config('telegram.bot_token')))->sendChatAction($params);
        } catch (\Throwable $e) {
            Log::warning('Failed to send Telegram typing indicator', [
                'chat_id' => $chatId,
                'message_thread_id' => $messageThreadId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function intervalSeconds(): int
    {
        return max(1, (int) config('agent.telegram.typing_interval_seconds', 4));
    }

    private function ttlSeconds(): int
    {
        return max(
            $this->intervalSeconds() + 1,
            (int) config('agent.telegram.typing_ttl_seconds', 150),
        );
    }

    private function cacheKey(string $sessionId): string
    {
        return 'telegram_typing:'.$sessionId;
    }
}
