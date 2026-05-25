<?php

namespace App\Services\Meeting;

use App\Domain\DTO\AI\MessageDTO;
use App\Models\MeetingSummary;
use App\Models\Setting;
use App\Models\Team;
use App\Services\LlmPromptService;
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
     * @return array{new_decisions: int, historical_decisions: int, estimated_chars: int}
     */
    public function preview(MeetingSummary $summary, Team $team): array
    {
        $decisions = array_values(array_filter((array) ($summary->decisions ?? [])));
        $event = $summary->calendarEvent;

        if (empty($decisions) || ! $event) {
            return ['new_decisions' => 0, 'historical_decisions' => 0, 'estimated_chars' => 0];
        }

        $cutoff = Carbon::parse($event->starts_at)->subMonths(self::HISTORY_MONTHS);
        $historical = $this->loadHistoricalSummaries($team, $event->id, $cutoff);
        $flat = $this->flattenDecisions($historical);

        $payload = json_encode([
            'new_decisions' => $decisions,
            'historical_decisions' => $flat,
        ], JSON_UNESCAPED_UNICODE);

        return [
            'new_decisions' => count($decisions),
            'historical_decisions' => count($flat),
            'estimated_chars' => strlen($payload),
        ];
    }

    /**
     * Detect new decisions that were already decided in previous team meetings.
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

        $cutoff = Carbon::parse($event->starts_at)->subMonths(self::HISTORY_MONTHS);

        $historical = $this->loadHistoricalSummaries($team, $event->id, $cutoff);
        $flatDecisions = $this->flattenDecisions($historical);

        if (empty($flatDecisions)) {
            return [];
        }

        return $this->callLlm($decisions, $flatDecisions, $event->title ?? '') ?? [];
    }

    private function loadHistoricalSummaries(Team $team, int $excludeEventId, Carbon $cutoff): Collection
    {
        return MeetingSummary::query()
            ->where('status', 'done')
            ->whereHas('calendarEvent', function ($q) use ($excludeEventId, $cutoff) {
                $q->where('id', '!=', $excludeEventId)
                    ->where('starts_at', '>=', $cutoff);
            })
            ->whereHas('calendarEvent.sources', function ($q) use ($team) {
                $q->whereHas('user.teams', fn ($q2) => $q2->where('teams.id', $team->id));
            })
            ->whereNotNull('decisions')
            ->orderByDesc('created_at')
            ->limit(50)
            ->with([
                'calendarEvent:id,title,starts_at',
                'calendarEvent.participants:calendar_event_id,name',
            ])
            ->get();
    }

    /**
     * @return array<int, array{id: int, text: string, date: string|null, meeting_title: string, participants: array, calendar_event_id: int|null}>
     */
    private function flattenDecisions(Collection $summaries): array
    {
        $result = [];
        $index = 0;

        foreach ($summaries as $summary) {
            $event = $summary->calendarEvent;
            $date = $event ? Carbon::parse($event->starts_at)->toDateString() : null;
            $title = $event?->title ?? '';
            $participants = $event?->participants->pluck('name')->filter()->values()->all() ?? [];

            foreach ((array) ($summary->decisions ?? []) as $text) {
                if (! trim((string) $text)) {
                    continue;
                }

                $result[] = [
                    'id' => $index,
                    'text' => $text,
                    'date' => $date,
                    'meeting_title' => $title,
                    'participants' => $participants,
                    'calendar_event_id' => $event?->id,
                ];

                $index++;

                if ($index >= self::MAX_HISTORICAL) {
                    return $result;
                }
            }
        }

        return $result;
    }

    private function callLlm(array $newDecisions, array $historicalDecisions, string $meetingTitle): ?array
    {
        $newList = array_map(
            fn (int $i, string $text) => ['new_index' => $i, 'text' => $text],
            array_keys($newDecisions),
            $newDecisions,
        );

        $userMessage = json_encode([
            'current_meeting_title' => $meetingTitle,
            'new_decisions' => $newList,
            'historical_decisions' => $historicalDecisions,
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

            return $this->buildResult($matches, $newDecisions, $historicalDecisions);
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
    private function buildResult(array $matches, array $newDecisions, array $historicalDecisions): array
    {
        $historicalById = collect($historicalDecisions)->keyBy('id');
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
                'previous_decision' => $prev['text'],
                'previous_date' => $prev['date'],
                'previous_meeting_title' => $prev['meeting_title'],
                'previous_participants' => $prev['participants'],
                'previous_calendar_event_id' => $prev['calendar_event_id'],
            ];
        }

        return $result;
    }

    private function buildSystemPrompt(): string
    {
        return app(LlmPromptService::class)->renderView(
            slug: 'meeting.repeated_discussions.system',
            organizationId: null,
            fallbackView: 'llm-prompts.meeting.repeated-discussions-system',
            name: 'Repeated discussion detection system prompt',
        );
    }
}
