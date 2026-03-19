<?php

namespace App\Services\Channel;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class TelegramTypingIndicator
{
    public function sessionId(int $chatId, ?int $messageThreadId = null): string
    {
        return sprintf('telegram:%s:%s', $chatId, $messageThreadId ?? 'root');
    }

    public function start(string $sessionId, int $chatId, ?int $messageThreadId = null): void
    {
        $payload = $this->get($sessionId) ?? [];
        $payload['chat_id'] = $chatId;
        $payload['message_thread_id'] = $messageThreadId;

        $this->persist($sessionId, $payload);
        $this->sendTypingIfDue($sessionId, $payload, true);
    }

    public function touch(string $sessionId): void
    {
        $payload = $this->get($sessionId);

        if ($payload === null) {
            return;
        }

        $this->persist($sessionId, $payload);
        $this->sendTypingIfDue($sessionId, $payload);
    }

    public function stop(string $sessionId): void
    {
        Cache::forget($this->cacheKey($sessionId));
        Cache::forget($this->lastSentCacheKey($sessionId));
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

    private function persist(string $sessionId, array $payload): void
    {
        Cache::put(
            $this->cacheKey($sessionId),
            $payload,
            now()->addSeconds($this->ttlSeconds()),
        );
    }

    private function sendTypingIfDue(string $sessionId, array $payload, bool $force = false): void
    {
        $lastSentAt = Cache::get($this->lastSentCacheKey($sessionId));
        $now = now()->getTimestamp();

        if (! $force && is_numeric($lastSentAt) && ($now - (int) $lastSentAt) < $this->intervalSeconds()) {
            return;
        }

        $this->sendTyping(
            (int) $payload['chat_id'],
            isset($payload['message_thread_id']) ? (int) $payload['message_thread_id'] : null,
        );

        Cache::put(
            $this->lastSentCacheKey($sessionId),
            $now,
            now()->addSeconds($this->ttlSeconds()),
        );
    }

    private function cacheKey(string $sessionId): string
    {
        return 'telegram_typing:'.$sessionId;
    }

    private function lastSentCacheKey(string $sessionId): string
    {
        return 'telegram_typing_last_sent:'.$sessionId;
    }
}
