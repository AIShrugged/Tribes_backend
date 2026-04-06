<?php

namespace App\Services\Meeting;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\FollowupStatus;
use App\Events\MeetingReviewGenerated;
use App\Models\AgentActivityLog;
use App\Models\CalendarEvent;
use App\Models\MeetingReview;
use App\Models\Setting;
use App\Services\Followup\TranscriptBuilderService;
use App\Services\OpenRouterClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class MeetingReviewService
{
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly TranscriptBuilderService $transcriptBuilder,
    ) {
    }

    public function generate(CalendarEvent $event): MeetingReview
    {
        $review = $event->meetingReview()->updateOrCreate([], [
            'status'           => FollowupStatus::IN_PROGRESS->value,
            'score'            => null,
            'score_breakdown'  => null,
            'key_insight'      => null,
            'suggestions'      => null,
            'agenda_analysis'  => null,
            'participation'    => null,
        ]);

        try {
            $transcript = $this->transcriptBuilder->build($event);
            $participation = $this->buildParticipationStats($event);
            $meta = $this->buildMeetingMeta($event);
            $history = $this->buildHistory($event);

            $json = $this->llm->chat(
                messages: [new MessageDTO('user', $this->buildPrompt($transcript, $participation, $meta, $history))],
                model: Setting::get('model.meeting_review', config('ai.providers.openrouter.models.meeting_review')),
                maxTokens: 8192,
                forceJsonResponse: true,
            );

            $data = json_decode($json, true);

            if (!$data) {
                preg_match('/\{[\s\S]*\}/s', $json, $matches);
                $data = json_decode($matches[0] ?? '{}', true);
            }

            $review->update([
                'status'                      => FollowupStatus::DONE->value,
                'score'                       => $data['score'] ?? null,
                'score_breakdown'             => $data['score_breakdown'] ?? null,
                'key_insight'                 => $data['key_insight'] ?? null,
                'suggestions'                 => $data['suggestions'] ?? [],
                'agenda_analysis'             => $data['agenda_analysis'] ?? null,
                'participation'               => $data['participation'] ?? $participation,
                'trend'                       => $data['trend'] ?? null,
                'previous_suggestions_check'  => $data['previous_suggestions_check'] ?? [],
            ]);

            MeetingReviewGenerated::dispatch($review->fresh());

            if ($event->source?->user) {
                AgentActivityLog::recordActivity(
                    user: $event->source->user,
                    toolName: 'meeting_review_generated',
                    toolResult: [
                        'score' => $review->score,
                        'event_id' => $event->id,
                    ],
                );
            }
        } catch (\Throwable $e) {
            Log::error('MeetingReviewService: generation failed', [
                'event_id' => $event->id,
                'error'    => $e->getMessage(),
            ]);
            $review->update(['status' => FollowupStatus::FAILED->value]);
        }

        return $review->fresh();
    }

    private function buildParticipationStats(CalendarEvent $event): array
    {
        $entries = $event->transcriptEntries()->with('participant')->get();

        $stats = [];
        foreach ($entries as $entry) {
            $name = $entry->participant?->name ?? 'Unknown';
            if (!isset($stats[$name])) {
                $stats[$name] = ['name' => $name, 'entries' => 0, 'words' => 0];
            }
            $stats[$name]['entries']++;
            $stats[$name]['words'] += str_word_count($entry->text);
        }

        $totalWords = array_sum(array_column($stats, 'words'));

        return array_values(array_map(function ($s) use ($totalWords) {
            return [
                'name'       => $s['name'],
                'entries'    => $s['entries'],
                'words'      => $s['words'],
                'percent'    => $totalWords > 0 ? round($s['words'] / $totalWords * 100, 1) : 0,
            ];
        }, $stats));
    }

    private function buildMeetingMeta(CalendarEvent $event): array
    {
        $durationMinutes = null;
        if ($event->starts_at && $event->ends_at) {
            $start = Carbon::parse($event->starts_at);
            $end = Carbon::parse($event->ends_at);
            $durationMinutes = round($start->diffInMinutes($end));
        }

        return [
            'title'       => $event->title,
            'description' => $event->description,
            'duration_min' => $durationMinutes,
            'participants_count' => $event->participants()->count(),
        ];
    }

    private function buildHistory(CalendarEvent $event): ?array
    {
        $sourceId = $event->source_id;

        $previousReviews = MeetingReview::where('status', 'done')
            ->whereHas('calendarEvent', function ($q) use ($sourceId, $event) {
                $q->where('source_id', $sourceId)
                  ->where('id', '!=', $event->id)
                  ->where('starts_at', '<', $event->starts_at);
            })
            ->orderByDesc('created_at')
            ->take(5)
            ->get();

        if ($previousReviews->isEmpty()) {
            return null;
        }

        $history = [];
        foreach ($previousReviews as $r) {
            $ce = $r->calendarEvent;
            $history[] = [
                'date'            => $ce?->starts_at,
                'title'           => $ce?->title,
                'score'           => $r->score,
                'score_breakdown' => $r->score_breakdown,
                'key_insight'     => $r->key_insight,
                'suggestions'     => $r->suggestions,
            ];
        }

        return $history;
    }

    private function buildPrompt(string $transcript, array $participation, array $meta, ?array $history): string
    {
        $participationJson = json_encode($participation, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $historyBlock = '';
        if ($history) {
            $historyJson = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $historyBlock = <<<HIST

            ## История предыдущих встреч (от новых к старым):
            {$historyJson}

            Используй эту историю для:
            1. **Тренд** — Сравни текущую встречу с предыдущими. Укажи, что улучшилось, а что ухудшилось.
               Добавь в JSON поле "trend" с кратким выводом о динамике.
            2. **Проверка рекомендаций** — Посмотри, какие рекомендации давались на предыдущих встречах.
               Были ли они выполнены? Добавь в JSON поле "previous_suggestions_check" — массив объектов:
               [{"suggestion": "<текст рекомендации>", "status": "implemented|ignored|partially", "comment": "<что видно из транскрипта>"}]
               Оценивай только те рекомендации, про которые можно сделать вывод из текущего транскрипта.

            HIST;
        }

        return <<<TXT
        Ты — опытный фасилитатор встреч и эксперт по эффективности командной работы.
        Проанализируй транскрипт встречи и оцени её эффективность.

        Твоя задача — дать **один самый важный инсайт** для повышения эффективности этой встречи,
        общую оценку и конкретные рекомендации. Представь, что ты был невидимым участником встречи
        и теперь делишься своим экспертным мнением.

        ## Метаданные встречи:
        {$metaJson}

        ## Статистика участия (предрасчитанная):
        {$participationJson}
        {$historyBlock}
        ## Критерии оценки (каждый от 1 до 10):
        - **goal_clarity** — Была ли чётко сформулирована цель/повестка встречи? Следовали ли ей?
        - **participation_balance** — Насколько равномерно участники вовлечены? (используй статистику выше)
        - **decisions_made** — Были ли приняты конкретные решения с ответственными и сроками?
        - **time_efficiency** — Насколько эффективно использовано время? Были ли затянутые или нерелевантные обсуждения?
        - **action_items_clarity** — Зафиксированы ли конкретные следующие шаги?

        ## Формат ответа (строго JSON):
        {
            "score": <среднее от всех критериев, число от 1.0 до 10.0>,
            "score_breakdown": {
                "goal_clarity": <1-10>,
                "participation_balance": <1-10>,
                "decisions_made": <1-10>,
                "time_efficiency": <1-10>,
                "action_items_clarity": <1-10>
            },
            "key_insight": "<Один самый важный инсайт — конкретное наблюдение, которое поможет команде проводить встречи эффективнее. Будь конкретен, приводи примеры из транскрипта.>",
            "suggestions": [
                "<Конкретная рекомендация 1>",
                "<Конкретная рекомендация 2>",
                "<Конкретная рекомендация 3>"
            ],
            "participation": [
                {"name": "<имя>", "assessment": "<краткая оценка вовлечённости участника>"}
            ],
            "agenda_analysis": {
                "had_clear_agenda": <true/false>,
                "discussed_topics": ["<тема 1>", "<тема 2>"],
                "unplanned_topics": ["<тема, которой не было в повестке>"],
                "missed_topics": ["<тема из описания встречи, которую не обсудили>"],
                "summary": "<Краткий вывод о том, насколько повестка была соблюдена>"
            },
            "trend": "<Сравнение с предыдущими встречами: что улучшилось, что ухудшилось. Если истории нет — null>",
            "previous_suggestions_check": [
                {"suggestion": "<рекомендация>", "status": "implemented|ignored|partially", "comment": "<вывод>"}
            ]
        }

        Если описания встречи нет (description = null), в agenda_analysis.missed_topics верни пустой массив
        и в summary напиши, что повестка не была задана заранее.
        Если истории предыдущих встреч нет — в trend верни null, в previous_suggestions_check верни пустой массив.

        Давай 2-4 рекомендации. Будь конкретен и практичен — избегай общих фраз вроде «улучшите коммуникацию».
        Отвечай только валидным JSON.

        ## Транскрипт встречи:
        {$transcript}
        TXT;
    }
}
