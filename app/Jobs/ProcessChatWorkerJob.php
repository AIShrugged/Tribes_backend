<?php

namespace App\Jobs;

use App\Enums\AgentTaskType;
use App\Enums\OutputMode;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Agent\AgentRunOptions;
use App\Services\Agent\AgentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessChatWorkerJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $chatId,
        public int $userId,
        public int $userMessageId,
        public int $assistantMessageId,
    ) {}

    public function handle(AgentService $agentService): void
    {
        $chat = Chat::find($this->chatId);
        $user = User::find($this->userId);
        $assistantMessage = ChatMessage::find($this->assistantMessageId);

        if (! $chat || ! $user || ! $assistantMessage) {
            return;
        }

        $runUuid = $assistantMessage->agent_run_uuid ?: (string) Str::uuid();

        $assistantMessage->update([
            'status' => 'processing',
            'agent_run_uuid' => $runUuid,
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

            $assistantMessage->update([
                'content' => $responseText,
                'status' => 'completed',
                'completed_at' => now(),
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Chat worker failed', [
                'chat_id' => $this->chatId,
                'assistant_message_id' => $this->assistantMessageId,
                'error' => $e->getMessage(),
            ]);

            $assistantMessage->update([
                'content' => 'Sorry, I encountered an error while processing your request.',
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }
}
