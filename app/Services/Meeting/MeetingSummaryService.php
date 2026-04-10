<?php

namespace App\Services\Meeting;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\FollowupStatus;
use App\Events\MeetingSummaryGenerated;
use App\Models\AgentActivityLog;
use App\Models\CalendarEvent;
use App\Models\MeetingSummary;
use App\Services\Followup\TranscriptBuilderService;
use App\Models\Setting;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\Log;

class MeetingSummaryService
{
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly TranscriptBuilderService $transcriptBuilder,
    ) {
    }

    public function generate(CalendarEvent $event): MeetingSummary
    {
        $summary = $event->meetingSummary()->updateOrCreate([], [
            'status'     => FollowupStatus::IN_PROGRESS->value,
            'title'      => null,
            'summary'    => null,
            'key_points' => null,
            'decisions'  => null,
        ]);

        try {
            $transcript = $this->transcriptBuilder->build($event);

            $json = $this->llm->chat(
                messages: [new MessageDTO('user', $this->buildPrompt($transcript, $event))],
                model: Setting::get('model.meeting_summary', config('ai.providers.openrouter.models.meeting_summary')),
                maxTokens: 4096,
                forceJsonResponse: true,
            );

            if (!preg_match('/\{[\s\S]*\}/s', $json, $matches)) {
                throw new \RuntimeException('No JSON in LLM response');
            }
            $data = json_decode($matches[0], true);
            if (!$data) {
                throw new \RuntimeException('Invalid JSON in LLM response');
            }

            $summary->update([
                'status'      => FollowupStatus::DONE->value,
                'title'       => $data['title'] ?? null,
                'summary'     => $data['summary'] ?? null,
                'key_points'  => $data['key_points'] ?? [],
                'decisions'   => $data['decisions'] ?? [],
                'commitments' => $data['commitments'] ?? [],
            ]);

            MeetingSummaryGenerated::dispatch($summary->fresh());

            if ($event->source?->user) {
                AgentActivityLog::recordActivity(
                    user: $event->source->user,
                    toolName: 'meeting_summary_generated',
                    toolResult: [
                        'title' => $summary->title,
                        'event_id' => $event->id,
                    ],
                );
            }
        } catch (\Throwable $e) {
            Log::error('MeetingSummaryService: generation failed', ['error' => $e->getMessage()]);
            $summary->update(['status' => FollowupStatus::FAILED->value]);
        }

        return $summary->fresh();
    }

    private function buildPrompt(string $transcript, CalendarEvent $event): string
    {
        $example = $this->getProtocolExample();
        $meetingDate = \Carbon\Carbon::parse($event->starts_at)->format('d.m.Y');
        $nextDay = \Carbon\Carbon::parse($event->starts_at)->addDay()->format('d.m.Y');

        return <<<TXT
        Составь протокол встречи по транскрипту. Протокол должен быть аналогичен примеру ниже.
        Дата встречи: {$meetingDate}. "Завтра" = {$nextDay}. Используй конкретные даты в дедлайнах.

        Верни JSON:
        {
            "title": "Краткое название встречи (до 10 слов)",
            "summary": "Протокол встречи в формате как в примере (markdown-строка)",
            "key_points": ["Факт 1 с именем участника", "Факт 2"],
            "decisions": ["Решение 1", "Решение 2"],
            "commitments": [
                {"who": "Имя", "what": "Что делает", "deadline": "Конкретная дата или null"}
            ]
        }

        === ПРИМЕР ПРОТОКОЛА ===
        {$example}
        === КОНЕЦ ПРИМЕРА ===

        Правила:
        - summary: формат ТОЧНО как в примере. Ключевые слова, краткое содержание по пунктам, таблица задач.
        - Дедлайны: пересчитывай в конкретные даты (не "завтра", а "10.04.2026").
        - key_points: 5-10 конкретных фактов с именами.
        - decisions: ТОЛЬКО явные решения из транскрипта. НЕ ВЫДУМЫВАЙ.
        - commitments: ТОЛЬКО явные обязательства. Лучше пустой массив, чем выдуманный.
        - НЕ придумывай факты, которых нет в транскрипте.

        Транскрипт встречи:
        {$transcript}
        TXT;
    }

    private function getProtocolExample(): string
    {
        return <<<'EXAMPLE'
        Ключевые слова
        Генерация агенды, Paperclip AI, Протокол встречи, Follow-up, Интеграция API

        Краткое содержание
        - Отчёт по генерации агенды: Борис и Иван протестировали два контекста (общий knowledge base + статус проекта) — результат получился слишком абстрактным. Приняли решение перейти к митинго-ориентированным контекстам.
        - Константин доложил по Paperclip AI: инструмент развёрнут локально, проведено тестирование передачи задач между агентами, сгенерированы три плана интеграции с бэкендом.
        - Фёдор поделился опытом: запустил команду агентов без ограничений, за 4 часа они выполнили 110 issue, сожгли ~200 USD. Вывод: агентов необходимо воспринимать как цифровых сотрудников с онбордингом, лимитами и точками проверки.
        - Обсудили архитектуру интеграции: гибридный подход — агенты планирования через Paperclip API, агенты работы с данными остаются на нашей стороне. Доступ к БД — только через API.
        - Фёдор сформулировал требования к формату протокола: по итогу встречи должно быть чётко видно, о чём договорились и кто взял какие обязательства.
        - Согласована последовательность: summary → follow-up (summary + задачи) → агенда следующей встречи.

        Задачи
        - Сгенерировать 3-5 вариантов протокола и отправить на валидацию в чат (10.04) — Борис
        - Доработать генерацию агенды: перейти на митинго-ориентированный контекст, наладить pipeline summary → follow-up → агенда — Борис + Иван
        - Интегрировать Paperclip AI через API: развернуть на VPS, настроить взаимодействие с бэкендом — Константин
        - Создать организационный календарь (только встречи организации, без персональных) — дедлайн: 10.04 — Слава
        - Переименовать репозитории в соответствии с актуальными названиями — Иван
        EXAMPLE;
    }
}
