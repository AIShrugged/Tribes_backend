<?php

namespace App\Services\Meeting;

use App\Domain\DTO\AI\MessageDTO;
use App\Models\CalendarEvent;
use App\Models\MeetingDecision;
use App\Models\MeetingSummary;
use App\Models\Setting;
use App\Models\Team;
use App\Services\OpenRouterClient;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class DetectRepeatedDiscussionsService
{
    private const HISTORY_MONTHS = 6;

    private const MAX_HISTORICAL = 100;

    public function __construct(
        private readonly OpenRouterClient $llm,
    ) {}

    /**
     * Persist decisions from summary and detect repeats against team history.
     *
     * @return array<int, array{new_decision: string, previous_decision: string, previous_date: string, previous_meeting_title: string, previous_participants: array}>
     */
    public function detect(MeetingSummary $summary, Team $team): array
    {
        $decisions = array_values(array_filter((array) ($summary->decisions ?? [])));

        if (empty($decisions)) {
            return [];
        }

        $event = $summary->calendarEvent;

        if (! $event) {
            return [];
        }

        $participants = $event->participants->pluck('name')->filter()->values()->all();
        $meetingDate = Carbon::parse($event->starts_at)->toDateString();

        $this->persistDecisions($decisions, $event, $team, $participants, $meetingDate);

        $historical = $this->loadHistoricalDecisions($team, $event->id, $meetingDate);

        if ($historical->isEmpty()) {
            return [];
        }

        return $this->callLlm($decisions, $historical, $event->title ?? '') ?? [];
    }

    private function persistDecisions(
        array $decisions,
        CalendarEvent $event,
        Team $team,
        array $participants,
        string $meetingDate,
    ): void {
        foreach ($decisions as $text) {
            MeetingDecision::firstOrCreate(
                [
                    'calendar_event_id' => $event->id,
                    'team_id' => $team->id,
                    'text' => $text,
                ],
                [
                    'participants' => $participants,
                    'meeting_date' => $meetingDate,
                ]
            );
        }
    }

    private function loadHistoricalDecisions(Team $team, int $excludeEventId, string $meetingDate): Collection
    {
        return MeetingDecision::query()
            ->where('team_id', $team->id)
            ->where('calendar_event_id', '!=', $excludeEventId)
            ->where('meeting_date', '>=', Carbon::parse($meetingDate)->subMonths(self::HISTORY_MONTHS)->toDateString())
            ->orderByDesc('meeting_date')
            ->limit(self::MAX_HISTORICAL)
            ->with('calendarEvent:id,title')
            ->get();
    }

    private function callLlm(array $newDecisions, Collection $historical, string $meetingTitle): ?array
    {
        $newList = array_map(
            fn (int $i, string $text) => ['new_index' => $i, 'text' => $text],
            array_keys($newDecisions),
            $newDecisions,
        );

        $historicalList = $historical->map(fn (MeetingDecision $d) => [
            'id' => $d->id,
            'text' => $d->text,
            'date' => $d->meeting_date->toDateString(),
            'meeting_title' => $d->calendarEvent?->title ?? '',
            'participants' => $d->participants ?? [],
        ])->values()->all();

        $userMessage = json_encode([
            'current_meeting_title' => $meetingTitle,
            'new_decisions' => $newList,
            'historical_decisions' => $historicalList,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $json = $this->llm->chat(
                messages: [
                    new MessageDTO('system', $this->buildSystemPrompt()),
                    new MessageDTO('user', $userMessage),
                ],
                model: Setting::get('model.meeting_summary', config('ai.providers.openrouter.models.meeting_summary')),
                maxTokens: 2048,
                forceJsonResponse: true,
            );

            if (is_string($json) && preg_match('/\{[\s\S]*\}/s', $json, $matches)) {
                $json = $matches[0];
            }

            $decoded = is_string($json) ? json_decode($json, true) : $json;
            $matches = $decoded['matches'] ?? [];

            if (! is_array($matches)) {
                return [];
            }

            return $this->buildResult($matches, $newDecisions, $historical);
        } catch (\Throwable $e) {
            Log::error('DetectRepeatedDiscussionsService: LLM call failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<int, array{new_index: int, historical_id: int}>  $matches
     */
    private function buildResult(array $matches, array $newDecisions, Collection $historical): array
    {
        $historicalById = $historical->keyBy('id');
        $result = [];

        foreach ($matches as $match) {
            $newIndex = $match['new_index'] ?? null;
            $historicalId = $match['historical_id'] ?? null;

            if ($newIndex === null || $historicalId === null) {
                continue;
            }

            $newText = $newDecisions[$newIndex] ?? null;
            $prev = $historicalById->get($historicalId);

            if (! $newText || ! $prev) {
                continue;
            }

            $result[] = [
                'new_decision' => $newText,
                'previous_decision' => $prev->text,
                'previous_date' => $prev->meeting_date->toDateString(),
                'previous_meeting_title' => $prev->calendarEvent?->title ?? '',
                'previous_participants' => $prev->participants ?? [],
            ];
        }

        return $result;
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Ты — аналитик встреч. Твоя задача: найти решения из текущей встречи, которые уже принимались на прошлых встречах той же команды.

Тебе передаются:
- "new_decisions" — решения, принятые на текущей встрече
- "historical_decisions" — решения прошлых встреч с датами

Для каждого нового решения определи: есть ли в историческом списке похожее по смыслу решение?

## Правила сравнения

Сравнивай по СМЫСЛУ, а не по словам. Примеры одного и того же решения:
- «Перейти на PostgreSQL» = «Решили использовать PostgreSQL для нового сервиса»
- «Нанять DevOps» = «Принять специалиста по инфраструктуре»

НЕ считай повтором:
- Уточнение или развитие ранее принятого решения («Добавить кэш к PostgreSQL» — не повтор «Перейти на PostgreSQL»)
- Решение по другому проекту/контексту
- Общие фразы без конкретного смысла

Возвращай ТОЛЬКО реальные смысловые повторы с высокой уверенностью.

## Формат ответа

Верни JSON строго в этом формате:
{
  "matches": [
    {
      "new_index": 0,
      "historical_id": 42
    }
  ]
}

Если повторов нет — верни: { "matches": [] }

Поля:
- new_index: индекс из "new_decisions"
- historical_id: id из "historical_decisions"

Каждое новое решение может совпасть максимум с одним историческим (самым близким по смыслу).
PROMPT;
    }
}
