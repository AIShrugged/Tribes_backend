<?php

namespace App\Services;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\MeetingTaskStatus;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Services\Followup\TranscriptBuilderService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class IssueExtractionService
{
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly TranscriptBuilderService $transcriptBuilder,
    ) {}

    /**
     * Extract actionable issues from a meeting transcript and persist them.
     *
     * @return Collection<int, Issue>
     */
    public function extract(CalendarEvent $event, Team $team, User $user): Collection
    {
        $transcript = $this->transcriptBuilder->build($event);

        if (blank($transcript)) {
            return collect();
        }

        $messages = [
            new MessageDTO('system', $this->buildSystemPrompt()),
            new MessageDTO('user', "Транскрипт встречи:\n".$transcript),
        ];

        try {
            $json = $this->llm->chat(
                messages: $messages,
                model: Setting::get('model.followup', config('ai.providers.openrouter.models.followup')),
                maxTokens: 4096,
                forceJsonResponse: true,
            );

            $decoded = is_string($json) ? json_decode($json, true) : $json;
            $items = $decoded['issues'] ?? [];
        } catch (\Throwable $e) {
            Log::error('Issue extraction failed', [
                'calendar_event_id' => $event->id,
                'team_id' => $team->id,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }

        $issues = collect();

        foreach ($items as $item) {
            $name = trim($item['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $issue = Issue::create([
                'user_id' => $user->id,
                'organization_id' => $team->organization_id,
                'team_id' => $team->id,
                'sourceable_type' => CalendarEvent::class,
                'sourceable_id' => $event->id,
                'name' => $name,
                'description' => $item['description'] ?? null,
                'type' => in_array($item['type'] ?? '', ['task', 'bug'], true) ? $item['type'] : 'task',
                'status' => MeetingTaskStatus::OPEN->value,
                'assignee_name' => $item['assignee_name'] ?? null,
            ]);

            $issues->push($issue);
        }

        Log::info('Issues extracted from transcript', [
            'calendar_event_id' => $event->id,
            'team_id' => $team->id,
            'count' => $issues->count(),
        ]);

        return $issues;
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Ты — ИИ-ассистент для извлечения actionable issues из транскриптов встреч.

Проанализируй транскрипт и извлеки все задачи, проблемы, баги и action items, которые были обсуждены.

Верни JSON строго в следующем формате:
{
  "issues": [
    {
      "name": "Краткое название задачи",
      "description": "Подробное описание: что нужно сделать, контекст из обсуждения, ожидаемый результат",
      "type": "task или bug",
      "assignee_name": "Имя ответственного (если упомянуто в разговоре, иначе null)"
    }
  ]
}

Правила:
- Извлекай только конкретные, actionable элементы (не общие обсуждения)
- Для каждого issue давай чёткое описание с контекстом из разговора
- Если задача связана с кодом/разработкой — укажи type: "task"
- Если обсуждается проблема/баг — укажи type: "bug"
- Если ответственный не назван явно — assignee_name: null
- Если actionable items не найдены — верни пустой массив: {"issues": []}
PROMPT;
    }
}
