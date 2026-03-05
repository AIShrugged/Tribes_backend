<?php

namespace App\Services\Meeting;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\MeetingTaskStatus;
use App\Models\CalendarEvent;
use App\Models\Task;
use App\Services\Followup\TranscriptBuilderService;
use App\Models\Setting;
use App\Services\OpenRouterClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class MeetingTaskService
{
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly TranscriptBuilderService $transcriptBuilder,
    ) {
    }

    public function extract(CalendarEvent $event): Collection
    {
        $transcript = $this->transcriptBuilder->build($event);

        try {
            $json = $this->llm->chat(
                messages: [new MessageDTO('user', $this->buildPrompt($transcript))],
                model: Setting::get('model.meeting_tasks', config('ai.providers.openrouter.models.meeting_tasks')),
                maxTokens: 4096,
                forceJsonResponse: true,
            );

            $items = json_decode($json, true);

            if (!is_array($items)) {
                return new Collection();
            }

            $event->tasks()->delete();

            foreach ($items as $item) {
                Task::create([
                    'taskable_type' => CalendarEvent::class,
                    'taskable_id'   => $event->id,
                    'title'         => $item['title'],
                    'description'   => $item['description'] ?? null,
                    'assignee_name' => $item['assignee_name'] ?? null,
                    'due_date'      => $item['due_date'] ?? null,
                    'status'        => MeetingTaskStatus::OPEN->value,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('MeetingTaskService: extraction failed', ['error' => $e->getMessage()]);
        }

        return $event->tasks()->get();
    }

    private function buildPrompt(string $transcript): string
    {
        return <<<TXT
        Проанализируй транскрипт встречи и извлеки все задачи, поручения и договорённости о действиях.
        Верни JSON-массив задач в следующем формате:
        [
            {
                "title": "Краткое название задачи",
                "description": "Подробное описание или null",
                "assignee_name": "Имя ответственного или null",
                "due_date": "YYYY-MM-DD или null"
            }
        ]

        Если задач нет — верни пустой массив [].
        Отвечай только валидным JSON без дополнительного текста.

        Транскрипт встречи:
        {$transcript}
        TXT;
    }
}
