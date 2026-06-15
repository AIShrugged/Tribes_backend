<?php

namespace App\Jobs;

use App\Enums\AgentTaskType;
use App\Enums\OutputMode;
use App\Exceptions\AppException;
use App\Models\ChannelMessage;
use App\Models\Chat;
use App\Models\User;
use App\Services\Agent\AgentRunOptions;
use App\Services\Agent\AgentService;
use App\Services\Channel\ChannelBus;
use App\Services\Channel\ChannelRuntimeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessChatWorkerJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public int $chatId,
        public int $userId,
        public int $userMessageId,
        public int $assistantMessageId,
    ) {
        // Interactive responses run on a dedicated queue so they aren't starved
        // behind heavy background jobs on the default queue.
        $this->onQueue('chat');
    }

    public function tries(): int
    {
        return (int) config('agent.chat.max_attempts', 3);
    }

    public function backoff(): array
    {
        return array_values((array) config('agent.chat.backoff_seconds', [10, 30]));
    }

    public function handle(
        AgentService $agentService,
        ChannelBus $channelBus,
        ChannelRuntimeService $runtimeService,
    ): void {
        $chat = Chat::find($this->chatId);
        $user = User::find($this->userId);
        $assistantMessage = ChannelMessage::find($this->assistantMessageId);

        if (! $chat || ! $user || ! $assistantMessage) {
            return;
        }

        $runUuid = $assistantMessage->agent_run_uuid ?: (string) Str::uuid();
        $currentAttempt = $this->attempts();
        $maxAttempts = $this->tries();

        $assistantMessage->markProcessing([
            'agent_run_uuid' => $runUuid,
            'current_attempt' => $currentAttempt,
            'max_attempts' => $maxAttempts,
            'next_retry_at' => null,
            'failure_code' => null,
            'error_message' => null,
        ]);

        try {
            $conversation = $channelBus->forChat($chat);

            $history = $this->loadRecentHistory($conversation);

            $userMessage = ChannelMessage::find($this->userMessageId);
            if (! $userMessage) {
                throw new \RuntimeException('User message not found');
            }

            $agentService->registerChatTools($chat, $user);

            $responseText = $agentService->run(
                $user,
                $history,
                $userMessage->content,
                new AgentRunOptions(
                    outputMode: OutputMode::MD,
                    taskType: AgentTaskType::INTERACTIVE,
                    conversationKey: 'chat:'.$chat->id,
                    chatId: $chat->id,
                    agentRunUuid: $runUuid,
                    organizationId: $chat->organization_id,
                    enableSqlTool: false,
                    maxTokens: config('ai.agent_max_tokens', 16000),
                    enableThinking: true,
                ),
                is_array($userMessage->metadata) ? $userMessage->metadata : [],
            );

            $runtimeService->deliverToWebChat($assistantMessage, $responseText);
        } catch (\Throwable $e) {
            $failureCode = $this->normalizeFailureCode($e);
            $nextRetryAt = $currentAttempt < $maxAttempts
                ? now()->addSeconds($this->nextBackoffSeconds($currentAttempt))
                : null;

            Log::error('Chat worker failed', [
                'chat_id' => $this->chatId,
                'assistant_message_id' => $this->assistantMessageId,
                'error' => $e->getMessage(),
                'attempt' => $currentAttempt,
                'max_attempts' => $maxAttempts,
                'failure_code' => $failureCode,
            ]);

            $attributes = [
                'content' => $currentAttempt < $maxAttempts
                    ? 'Processing...'
                    : 'Sorry, I encountered an error while processing your request.',
                'error_message' => $e->getMessage(),
                'failure_code' => $failureCode,
                'next_retry_at' => $nextRetryAt,
                'completed_at' => $currentAttempt < $maxAttempts ? null : now(),
            ];

            if ($currentAttempt < $maxAttempts) {
                $assistantMessage->markRetrying($attributes);
            } else {
                $assistantMessage->markFailed($attributes);
            }

            throw $e;
        }
    }

    /**
     * Load a bounded, recent slice of chat history (mirrors the Telegram worker)
     * so long-running conversations don't load unbounded history every turn.
     */
    private function loadRecentHistory(\App\Models\ChannelConversation $conversation): \Illuminate\Support\Collection
    {
        $limit = (int) config('agent.chat.history_limit', 30);
        $windowHours = (int) config('agent.chat.history_window_hours', 24);

        return $conversation->messages()
            ->where('id', '<', $this->userMessageId)
            ->where('created_at', '>=', now()->subHours($windowHours))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    private function nextBackoffSeconds(int $currentAttempt): int
    {
        $backoff = $this->backoff();

        if ($backoff === []) {
            return 0;
        }

        return (int) ($backoff[$currentAttempt - 1] ?? end($backoff));
    }

    private function normalizeFailureCode(\Throwable $e): string
    {
        if ($e instanceof AppException && $e->getErrorCode() !== '') {
            return $e->getErrorCode();
        }

        return str($e::class)
            ->afterLast('\\')
            ->snake()
            ->upper()
            ->toString();
    }
}
