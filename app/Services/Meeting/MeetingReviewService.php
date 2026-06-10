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
use App\Services\LlmPromptService;
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

    /**
     * @param  bool  $dispatchNotification  When false (manual upload + moderation), the review row is
     *   still generated but MeetingReviewGenerated is NOT dispatched — the approve handler re-fires it.
     */
    public function generate(CalendarEvent $event, bool $dispatchNotification = true): MeetingReview
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

            if ($dispatchNotification) {
                MeetingReviewGenerated::dispatch($review->fresh());
            }

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

        return app(LlmPromptService::class)->renderView(
            slug: 'meeting.review.user',
            organizationId: null,
            fallbackView: 'llm-prompts.meeting.review-user',
            variables: [
                'meta_json' => $metaJson,
                'participation_json' => $participationJson,
                'history_block' => $historyBlock,
                'transcript' => $transcript,
            ],
            name: 'Meeting review prompt',
        );
    }
}
