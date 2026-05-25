<?php

namespace App\Services\Agenda;

use App\Domain\DTO\AI\MessageDTO;
use App\Models\CalendarEvent;
use App\Models\MeetingSeriesState;
use App\Models\MeetingSummary;
use App\Models\Setting;
use App\Services\LlmPromptService;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\Log;

class MeetingSeriesStateService
{
    public function updateAfterMeeting(CalendarEvent $event, MeetingSummary $summary): void
    {
        $seriesId = MeetingSeriesState::buildSeriesIdentifier($event);

        $state = MeetingSeriesState::where('series_identifier', $seriesId)->first();
        $currentContent = $state?->content;
        $version = ($state?->version ?? 0) + 1;

        try {
            $prompt = $this->buildFoldPrompt($currentContent, $event, $summary);
            $response = app(OpenRouterClient::class)->chat(
                messages: [new MessageDTO('user', $prompt)],
                model: Setting::get('model.meeting_summary', config('ai.providers.openrouter.models.meeting_summary')),
                maxTokens: 4096,
            );

            MeetingSeriesState::updateOrCreate(
                ['series_identifier' => $seriesId],
                [
                    'content' => trim($response),
                    'source_event_id' => $event->id,
                    'version' => $version,
                ],
            );
        } catch (\Throwable $e) {
            Log::error('Meeting series state update failed', [
                'series_identifier' => $seriesId,
                'calendar_event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildFoldPrompt(
        ?string $currentState,
        CalendarEvent $event,
        MeetingSummary $summary,
    ): string {
        $parts = [];

        $parts[] = 'Ты обновляешь "текущее состояние проекта" для серии регулярных встреч.';
        $parts[] = 'Твоя задача — поддерживать актуальный документ, отражающий текущее положение дел.';
        $parts[] = '';

        if ($currentState) {
            $parts[] = '=== ТЕКУЩЕЕ СОСТОЯНИЕ ===';
            $parts[] = $currentState;
        } else {
            $parts[] = '=== ТЕКУЩЕЕ СОСТОЯНИЕ ===';
            $parts[] = 'Это первая встреча в серии. Состояния пока нет.';
        }

        $parts[] = '';
        $parts[] = "=== ИТОГИ ПОСЛЕДНЕЙ ВСТРЕЧИ ({$event->starts_at->format('d.m.Y')}) ===";
        $parts[] = "Название: {$event->title}";

        if ($summary->summary) {
            $parts[] = "Краткое содержание: {$summary->summary}";
        }

        if (! empty($summary->key_points)) {
            $parts[] = 'Ключевые моменты:';
            foreach ($summary->key_points as $point) {
                $parts[] = "- {$point}";
            }
        }

        if (! empty($summary->decisions)) {
            $parts[] = 'Принятые решения:';
            foreach ($summary->decisions as $decision) {
                $parts[] = "- {$decision}";
            }
        }

        $parts[] = '';
        $parts[] = '=== ИНСТРУКЦИИ ===';
        $parts[] = 'Обнови состояние проекта, учитывая итоги последней встречи.';
        $parts[] = '';
        $parts[] = 'Правила:';
        $parts[] = '- Добавь новые решения, вехи, изменения направления';
        $parts[] = '- Выполненные задачи перенеси в раздел "Завершённые вехи"';
        $parts[] = '- НИКОГДА не удаляй стратегические решения и решения "НЕ делать что-то"';
        $parts[] = '- Убирай только то, что явно отменено или потеряло актуальность';
        $parts[] = '- Держи документ компактным — до 800 слов';
        $parts[] = '';
        $parts[] = 'Структура документа:';
        $parts[] = '## Завершённые вехи';
        $parts[] = '(что уже сделано и работает)';
        $parts[] = '';
        $parts[] = '## Активные направления';
        $parts[] = '(над чем сейчас работаем)';
        $parts[] = '';
        $parts[] = '## Принятые решения';
        $parts[] = '(архитектурные и стратегические решения, включая "НЕ делать")';
        $parts[] = '';
        $parts[] = '## Отложено / Отклонено';
        $parts[] = '(что сознательно решили не делать или отложить)';
        $parts[] = '';
        $parts[] = 'Верни ТОЛЬКО обновлённый документ, без пояснений.';

        return app(LlmPromptService::class)->renderView(
            slug: 'agenda.meeting_series_state.user',
            organizationId: $event->source?->organization_id,
            fallbackView: 'llm-prompts.shared.prompt-body',
            variables: ['prompt_body' => implode("\n", $parts)],
            name: 'Meeting series state prompt',
        );
    }
}
