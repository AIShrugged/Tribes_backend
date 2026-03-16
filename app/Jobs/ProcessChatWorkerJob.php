<?php

namespace App\Jobs;

use App\Enums\AgentTaskType;
use App\Enums\ChatRunStatus;
use App\Enums\OutputMode;
use App\Exceptions\AppException;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Agent\AgentRunOptions;
use App\Services\Agent\AgentService;
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
    ) {}

    public function tries(): int
    {
        return (int) config('agent.chat.max_attempts', 3);
    }

    public function backoff(): array
    {
        return array_values((array) config('agent.chat.backoff_seconds', [10, 30]));
    }

    public function handle(AgentService $agentService): void
    {
        $chat = Chat::find($this->chatId);
        $user = User::find($this->userId);
        $assistantMessage = ChatMessage::find($this->assistantMessageId);

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
            $history = $chat->messages()
                ->where('id', '<', $this->userMessageId)
                ->orderBy('created_at')
                ->get();

            $userMessage = ChatMessage::find($this->userMessageId);
            if (! $userMessage) {
                throw new \RuntimeException('User message not found');
            }

            $agentService->registerChatTools($chat);

            $responseText = $agentService->run(
                $user,
                $history,
                $userMessage->content,
                new AgentRunOptions(
                    outputMode: OutputMode::MD,
                    taskType: AgentTaskType::INTERACTIVE,
                    conversationKey: 'chat:'.$chat->id,
                )
            );

            $assistantMessage->markCompleted([
                'content' => $responseText,
                'completed_at' => now(),
                'error_message' => null,
                'failure_code' => null,
                'next_retry_at' => null,
            ]);
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
