<?php

namespace App\Jobs;

use App\Enums\AgentTaskType;
use App\Enums\OutputMode;
use App\Models\ChannelIdentity;
use App\Models\ChannelMessage;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ProcessTelegramWorkerJob implements ShouldQueue
{
    use Queueable;

    private const HISTORY_LIMIT = 30;

    private const HISTORY_WINDOW_HOURS = 24;

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

            $conversation = $channelBus->forTelegram($this->chatId, $this->messageThreadId);
            $history = $this->loadRecentHistory($conversation->id);

            // Resolve user's primary organization for team roster context
            $organizationId = $user->organizations()
                ->orderByDesc('organization_user.created_at')
                ->value('organizations.id');

            $response = $agentService->run(
                $user,
                $history,
                $this->content,
                new AgentRunOptions(
                    channel: 'telegram',
                    outputMode: OutputMode::MD,
                    taskType: AgentTaskType::INTERACTIVE,
                    conversationKey: sprintf('telegram:%s:%s', $this->chatId, $this->messageThreadId ?? 'root'),
                    progressCallback: function () use ($typingIndicator, $typingSessionId): void {
                        $typingIndicator->touch($typingSessionId);
                    },
                    organizationId: $organizationId,
                    enableSqlTool: false,
                    maxTokens: config('ai.agent_max_tokens', 16000),
                    enableThinking: true,
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

    private function loadRecentHistory(int $conversationId): Collection
    {
        $batchMinId = ChannelMessage::query()
            ->where('agent_batch_uuid', $this->batchUuid)
            ->min('id');

        $query = ChannelMessage::query()
            ->where('conversation_id', $conversationId)
            ->whereIn('role', ['user', 'assistant'])
            ->where('created_at', '>=', now()->subHours(self::HISTORY_WINDOW_HOURS))
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT);

        if ($batchMinId !== null) {
            $query->where('id', '<', $batchMinId);
        }

        return $query->get()->reverse()->values();
    }
}
