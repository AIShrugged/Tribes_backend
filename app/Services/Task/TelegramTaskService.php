<?php

namespace App\Services\Task;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\MeetingTaskStatus;
use App\Models\Task;
use App\Models\TelegramChatMessage;
use App\Services\OpenRouterClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class TelegramTaskService
{
    /** How many hours back to scan for new messages */
    private const SCAN_WINDOW_HOURS = 4;

    public function __construct(
        private readonly OpenRouterClient $llm,
    ) {
    }

    /**
     * Process all Telegram chats that have recent messages.
     * Returns the number of chats processed.
     */
    public function processAll(): int
    {
        $chatIds = TelegramChatMessage::where('created_at', '>=', now()->subHours(self::SCAN_WINDOW_HOURS))
            ->select('telegram_chat_id')
            ->distinct()
            ->pluck('telegram_chat_id');

        $processed = 0;

        foreach ($chatIds as $chatId) {
            try {
                if ($this->processChat($chatId)) {
                    $processed++;
                }
            } catch (\Throwable $e) {
                Log::error('TelegramTaskService: failed to process chat', [
                    'telegram_chat_id' => $chatId,
                    'error'            => $e->getMessage(),
                ]);
            }
        }

        return $processed;
    }

    /**
     * Process a single Telegram chat: extract new tasks and update statuses.
     * Returns true if any tasks were created or updated.
     */
    public function processChat(int $chatId): bool
    {
        $recentMessages = TelegramChatMessage::where('telegram_chat_id', $chatId)
            ->where('role', 'user')
            ->where('created_at', '>=', now()->subHours(self::SCAN_WINDOW_HOURS))
            ->orderBy('created_at')
            ->get();

        if ($recentMessages->isEmpty()) {
            return false;
        }

        $allMessageIds = TelegramChatMessage::where('telegram_chat_id', $chatId)->pluck('id');

        $openTasks = Task::where('taskable_type', TelegramChatMessage::class)
            ->whereIn('taskable_id', $allMessageIds)
            ->whereNotIn('status', [MeetingTaskStatus::DONE->value, MeetingTaskStatus::CANCELLED->value])
            ->get();

        $result = $this->callLLM($recentMessages, $openTasks);

        if (! $result) {
            return false;
        }

        $changed = false;

        foreach ($result['new_tasks'] ?? [] as $taskData) {
            $title = trim($taskData['title'] ?? '');
            if (! $title) {
                continue;
            }

            $messageId = $taskData['message_id'] ?? $recentMessages->last()->id;

            Task::create([
                'taskable_type' => TelegramChatMessage::class,
                'taskable_id'   => $messageId,
                'title'         => $title,
                'description'   => $taskData['description'] ?? null,
                'assignee_name' => $taskData['assignee_name'] ?? null,
                'due_date'      => $taskData['due_date'] ?? null,
                'status'        => MeetingTaskStatus::OPEN->value,
            ]);

            $changed = true;

            Log::info('TelegramTaskService: task created', [
                'telegram_chat_id' => $chatId,
                'title'            => $title,
                'message_id'       => $messageId,
            ]);
        }

        foreach ($result['status_updates'] ?? [] as $update) {
            $task      = $openTasks->firstWhere('id', $update['task_id'] ?? null);
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

            Log::info('TelegramTaskService: task status updated', [
                'task_id'    => $task->id,
                'new_status' => $newStatus,
            ]);
        }

        return $changed;
    }

    private function callLLM(Collection $messages, Collection $existingTasks): ?array
    {
        $messagesText = $messages->map(
            fn ($m) => "[ID:{$m->id}] {$m->content}"
        )->join("\n");

        $tasksText = $existingTasks->isEmpty()
            ? 'Активных задач нет.'
            : $existingTasks->map(
                fn ($t) => "[ID:{$t->id}] {$t->title} (статус: {$t->status})"
            )->join("\n");

        $prompt = <<<PROMPT
Ты — ассистент, который анализирует переписку в Telegram и отслеживает задачи.

Вот последние сообщения из чата:
{$messagesText}

Вот текущие активные задачи из этого чата:
{$tasksText}

Твоя задача:
1. Найди в новых сообщениях упоминания новых задач, поручений или договорённостей о действиях.
2. Найди в новых сообщениях упоминания о выполнении, отмене или изменении статуса существующих задач.

Верни JSON в следующем формате:
{
    "new_tasks": [
        {
            "message_id": <ID сообщения, в котором упоминается задача>,
            "title": "Краткое название задачи",
            "description": "Подробное описание или null",
            "assignee_name": "Имя ответственного или null",
            "due_date": "YYYY-MM-DD или null"
        }
    ],
    "status_updates": [
        {
            "task_id": <ID существующей задачи>,
            "status": "done|cancelled|in_progress|open"
        }
    ]
}

Если новых задач нет — верни пустой массив для new_tasks.
Если изменений статусов нет — верни пустой массив для status_updates.
Отвечай только валидным JSON без дополнительного текста.
PROMPT;

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
