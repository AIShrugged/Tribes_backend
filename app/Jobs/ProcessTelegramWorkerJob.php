<?php

namespace App\Jobs;

use App\Enums\AgentTaskType;
use App\Enums\OutputMode;
use App\Models\TelegramChatMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Agent\AgentRunOptions;
use App\Services\Agent\AgentService;
use App\Services\Agent\TelegramMessageCoalescer;
use App\Services\Agent\Tools\GetChatHistoryTool;
use App\Services\Agent\Tools\ToolRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class ProcessTelegramWorkerJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $chatId,
        public int $telegramUserId,
        public int $userId,
        public string $batchUuid,
        public string $content,
        public ?int $messageThreadId = null,
    ) {}

    public function handle(
        AgentService $agentService,
        ToolRegistry $toolRegistry,
        TelegramMessageCoalescer $coalescer,
    ): void {
        $telegramUser = TelegramUser::find($this->telegramUserId);
        $user = User::find($this->userId);

        if (! $telegramUser || ! $user) {
            $coalescer->releaseBatch($this->batchUuid);

            return;
        }

        try {
            $toolRegistry->register(new GetChatHistoryTool($this->chatId));

            $response = $agentService->run(
                $user,
                collect(),
                $this->content,
                new AgentRunOptions(
                    channel: 'telegram',
                    outputMode: OutputMode::MD,
                    taskType: AgentTaskType::INTERACTIVE,
                    conversationKey: 'telegram:'.$this->chatId,
                )
            );

            $telegram = new Api(config('telegram.bot_token'));
            $params = [
                'chat_id' => $this->chatId,
                'text' => $response,
                'parse_mode' => 'Markdown',
            ];

            if ($this->messageThreadId) {
                $params['message_thread_id'] = $this->messageThreadId;
            }

            $telegram->sendMessage($params);

            TelegramChatMessage::create([
                'telegram_chat_id' => $this->chatId,
                'telegram_user_id' => $telegramUser->telegram_user_id,
                'message_thread_id' => $this->messageThreadId,
                'role' => 'assistant',
                'content' => $response,
                'agent_batch_uuid' => $this->batchUuid,
                'responded_at' => now(),
            ]);

            $coalescer->markBatchResponded($this->batchUuid);
        } catch (\Throwable $e) {
            Log::error('Telegram worker failed', [
                'chat_id' => $this->chatId,
                'batch_uuid' => $this->batchUuid,
                'error' => $e->getMessage(),
            ]);

            $coalescer->releaseBatch($this->batchUuid);

            throw $e;
        }
    }
}
