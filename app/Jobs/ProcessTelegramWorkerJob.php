<?php

namespace App\Jobs;

use App\Enums\AgentTaskType;
use App\Enums\OutputMode;
use App\Models\ChannelIdentity;
use App\Models\User;
use App\Services\Agent\AgentRunOptions;
use App\Services\Agent\AgentService;
use App\Services\Agent\TelegramMessageCoalescer;
use App\Services\Agent\Tools\GetChatHistoryTool;
use App\Services\Agent\Tools\ToolRegistry;
use App\Services\Channel\ChannelBus;
use App\Services\Channel\ChannelRuntimeService;
use App\Services\Channel\TelegramTypingIndicator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessTelegramWorkerJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $chatId,
        public int $authorIdentityId,
        public int $userId,
        public string $batchUuid,
        public string $content,
        public ?int $messageThreadId = null,
    ) {}

    public function handle(
        AgentService $agentService,
        ToolRegistry $toolRegistry,
        TelegramMessageCoalescer $coalescer,
        ChannelBus $channelBus,
        ChannelRuntimeService $runtimeService,
        TelegramTypingIndicator $typingIndicator,
    ): void {
        $authorIdentity = ChannelIdentity::find($this->authorIdentityId);
        $user = User::find($this->userId);

        if (! $authorIdentity || ! $user) {
            $coalescer->releaseBatch($this->batchUuid);

            return;
        }

        try {
            $toolRegistry->register(new GetChatHistoryTool($this->chatId));
            $typingSessionId = $typingIndicator->sessionId($this->chatId, $this->messageThreadId);
            $typingIndicator->start($typingSessionId, $this->chatId, $this->messageThreadId);

            $response = $agentService->run(
                $user,
                collect(),
                $this->content,
                new AgentRunOptions(
                    channel: 'telegram',
                    outputMode: OutputMode::MD,
                    taskType: AgentTaskType::INTERACTIVE,
                    conversationKey: sprintf('telegram:%s:%s', $this->chatId, $this->messageThreadId ?? 'root'),
                    progressCallback: function () use ($typingIndicator): void {
                        $typingIndicator->touch($this->batchUuid);
                    },
                )
            );

            $runtimeService->deliverToConversation(
                $channelBus->forTelegram($this->chatId, $this->messageThreadId),
                $response,
                $authorIdentity,
                [
                    'agent_batch_uuid' => $this->batchUuid,
                    'responded_at' => now(),
                ]
            );

            $coalescer->markBatchResponded($this->batchUuid);
        } catch (\Throwable $e) {
            Log::error('Telegram worker failed', [
                'chat_id' => $this->chatId,
                'batch_uuid' => $this->batchUuid,
                'error' => $e->getMessage(),
            ]);

            $coalescer->releaseBatch($this->batchUuid);

            throw $e;
        } finally {
            $typingIndicator->stop($typingSessionId ?? $typingIndicator->sessionId($this->chatId, $this->messageThreadId));
        }
    }
}
