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
        private readonly ParticipantProfileMatchingService $participantMatcher,
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

        // Match participants to profiles via LLM, then link profile_id to tasks
        $this->participantMatcher->match($event);
        $this->linkTaskProfiles($event);

        return $event->tasks()->get();
    }

    /**
     * After participant matching, fill task.profile_id by comparing assignee_name
     * with participant names that already have a profile_id.
     */
    private function linkTaskProfiles(CalendarEvent $event): void
    {
        $tasks = $event->tasks()->whereNull('profile_id')->whereNotNull('assignee_name')->get();

        if ($tasks->isEmpty()) {
            return;
        }

        $participants = $event->participants()->whereNotNull('profile_id')->get();

        if ($participants->isEmpty()) {
            return;
        }

        foreach ($tasks as $task) {
            $assigneeLower = mb_strtolower($task->assignee_name);

            $matched = $participants->first(function ($participant) use ($assigneeLower) {
                $participantLower = mb_strtolower($participant->name);
                return str_contains($participantLower, $assigneeLower)
                    || str_contains($assigneeLower, $participantLower)
                    || str_contains($assigneeLower, mb_strtolower(explode(' ', $participant->name)[0] ?? ''));
            });

            if ($matched) {
                $task->update(['profile_id' => $matched->profile_id]);
            }
        }
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
