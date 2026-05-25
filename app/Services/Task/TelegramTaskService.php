<?php

namespace App\Services\Task;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\ConversationChannelType;
use App\Enums\MeetingTaskStatus;
use App\Models\AgentActivityLog;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Issue;
use App\Models\Organization;
use App\Services\LlmPromptService;
use App\Services\OpenRouterClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class TelegramTaskService
{
    private const SCAN_WINDOW_HOURS = 4;

    public function __construct(
        private readonly OpenRouterClient $llm,
    ) {
    }

    public function processAll(): int
    {
        $conversationIds = ChannelMessage::query()
            ->where('created_at', '>=', now()->subHours(self::SCAN_WINDOW_HOURS))
            ->whereHas('conversation', function ($query) {
                $query->where('channel_type', ConversationChannelType::TELEGRAM->value);
            })
            ->select('conversation_id')
            ->distinct()
            ->pluck('conversation_id');

        $processed = 0;

        foreach ($conversationIds as $conversationId) {
            try {
                if ($this->processConversation((int) $conversationId)) {
                    $processed++;
                }
            } catch (\Throwable $e) {
                Log::error('TelegramTaskService: failed to process chat', [
                    'conversation_id' => $conversationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $processed;
    }

    public function processChat(int $chatId): bool
    {
        $conversation = ChannelConversation::query()
            ->where('channel_type', ConversationChannelType::TELEGRAM->value)
            ->where('telegram_chat_id', $chatId)
            ->whereNull('message_thread_id')
            ->first();

        if (! $conversation) {
            return false;
        }

        return $this->processConversation($conversation->id);
    }

    private function processConversation(int $conversationId): bool
    {
        $conversation = ChannelConversation::query()->findOrFail($conversationId);

        if ($conversation->organization_id === null || $conversation->user_id === null) {
            return false;
        }

        $recentMessages = ChannelMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'user')
            ->where('created_at', '>=', now()->subHours(self::SCAN_WINDOW_HOURS))
            ->orderBy('created_at')
            ->with('authorIdentity:id,user_id')
            ->get();

        if ($recentMessages->isEmpty()) {
            return false;
        }

        $allMessageIds = ChannelMessage::where('conversation_id', $conversationId)->pluck('id');

        $openTasks = Issue::where('sourceable_type', ChannelMessage::class)
            ->whereIn('sourceable_id', $allMessageIds)
            ->where('status', '!=', MeetingTaskStatus::DONE->value)
            ->get();

        $orgContext = Organization::find($conversation->organization_id)?->context;

        $result = $this->callLLM($recentMessages, $openTasks, $orgContext, $conversation->organization_id);

        if (! $result) {
            return false;
        }

        $changed = false;
        $createdTasks = 0;
        $updatedTasks = 0;

        foreach ($result['new_tasks'] ?? [] as $taskData) {
            $name = trim($taskData['title'] ?? '');
            if (! $name) {
                continue;
            }

            $messageId = (int) ($taskData['message_id'] ?? $recentMessages->last()->id);
            $authorMessage = $recentMessages->firstWhere('id', $messageId);
            $authorUserId = $authorMessage?->authorIdentity?->user_id ?? $conversation->user_id;

            Issue::create([
                'user_id' => $authorUserId,
                'organization_id' => $conversation->organization_id,
                'team_id' => $conversation->team_id,
                'sourceable_type' => ChannelMessage::class,
                'sourceable_id' => $messageId,
                'name' => $name,
                'description' => $taskData['description'] ?? null,
                'assignee_name' => $taskData['assignee_name'] ?? null,
                'due_date' => $taskData['due_date'] ?? null,
                'type' => Issue::TYPE_ORGANIZATION,
                'status' => MeetingTaskStatus::OPEN->value,
            ]);

            $changed = true;
            $createdTasks++;

            Log::info('TelegramTaskService: task created', [
                'conversation_id' => $conversationId,
                'name' => $name,
                'message_id' => $messageId,
                'author_user_id' => $authorUserId,
            ]);
        }

        foreach ($result['status_updates'] ?? [] as $update) {
            $task = $openTasks->firstWhere('id', $update['task_id'] ?? null);
            $newStatus = $update['status'] ?? null;

            if (! $task || ! $newStatus) {
                continue;
            }

            $validStatuses = array_map(fn ($s) => $s->value, MeetingTaskStatus::cases());
            if (! in_array($newStatus, $validStatuses, true)) {
                continue;
            }

            $task->update(['status' => $newStatus]);
            $changed = true;
            $updatedTasks++;

            Log::info('TelegramTaskService: task status updated', [
                'task_id' => $task->id,
                'new_status' => $newStatus,
            ]);
        }

        if ($changed && $conversation->user) {
            AgentActivityLog::recordActivity(
                user: $conversation->user,
                toolName: 'telegram_tasks_processed',
                toolResult: [
                    'count' => $createdTasks + $updatedTasks,
                    'created_tasks' => $createdTasks,
                    'updated_tasks' => $updatedTasks,
                    'conversation_id' => $conversationId,
                ],
            );
        }

        return $changed;
    }

    private function callLLM(Collection $messages, Collection $existingTasks, ?string $orgContext = null, ?int $organizationId = null): ?array
    {
        $messagesText = $messages->map(
            fn ($m) => "[ID:{$m->id}] {$m->content}"
        )->join("\n");

        $tasksText = $existingTasks->isEmpty()
            ? 'Активных задач нет.'
            : $existingTasks->map(
                fn ($t) => "[ID:{$t->id}] {$t->name} (статус: {$t->status})"
            )->join("\n");

        $contextBlock = $orgContext
            ? "\n## Контекст организации\n\nИспользуй это для лучшего понимания предметной области, ролей команды и терминологии при определении задач:\n\n{$orgContext}\n"
            : '';

        $prompt = app(LlmPromptService::class)->renderView(
            slug: 'telegram.tasks.user',
            organizationId: $organizationId,
            fallbackView: 'llm-prompts.telegram.tasks-user',
            variables: [
                'context_block' => $contextBlock,
                'messages' => $messagesText,
                'tasks' => $tasksText,
            ],
            name: 'Telegram task extraction prompt',
        );

        try {
            $json = $this->llm->chat(
                messages: [new MessageDTO('user', $prompt)],
                model: config('ai.providers.openrouter.models.meeting_tasks'),
                maxTokens: 2048,
                forceJsonResponse: true,
            );

            $result = json_decode($json, true);

            return is_array($result) ? $result : null;
        } catch (\Throwable $e) {
            Log::error('TelegramTaskService: LLM call failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
