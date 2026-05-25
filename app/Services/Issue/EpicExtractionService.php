<?php

namespace App\Services\Issue;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\MeetingTaskStatus;
use App\Models\CalendarEvent;
use App\Models\Decision;
use App\Models\Issue;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Services\Followup\TranscriptBuilderService;
use App\Services\LlmPromptService;
use App\Services\OpenRouterClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class EpicExtractionService
{
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly TranscriptBuilderService $transcriptBuilder,
        private readonly EpicMergeService $epicMerge,
    ) {}

    /**
     * Extract long-term goals (epics) from meeting transcript + summary, merge with existing.
     *
     * @return array{created: Collection<int, Issue>, updated: Collection<int, Issue>}
     */
    public function extract(CalendarEvent $event, Team $team, User $owner, Collection $meetingIssues): array
    {
        if ($meetingIssues->isEmpty()) {
            // Эпик имеет смысл только если у митинга есть дочерние задачи, иначе нечего объединять.
            return ['created' => collect(), 'updated' => collect()];
        }

        $transcript = $this->transcriptBuilder->build($event);
        if (blank($transcript)) {
            return ['created' => collect(), 'updated' => collect()];
        }

        $existingEpics = Issue::query()
            ->where('organization_id', $team->organization_id)
            ->where(function ($q): void {
                $q->where('type', Issue::TYPE_EPIC)
                  ->orWhereHas('issueType', fn ($it) => $it->where('base_type', 'epic'));
            })
            ->whereIn('status', [MeetingTaskStatus::OPEN->value, MeetingTaskStatus::IN_PROGRESS->value])
            ->get(['id', 'name', 'description', 'team_id']);

        $decisions = Decision::query()
            ->where('calendar_event_id', $event->id)
            ->get(['id', 'text', 'topic', 'author_raw_name']);

        $items = $this->callLlm($event, $team, $transcript, $existingEpics, $meetingIssues, $decisions);

        if (empty($items)) {
            return ['created' => collect(), 'updated' => collect()];
        }

        return $this->epicMerge->persist(
            $items,
            $event,
            $team,
            $owner,
            $existingEpics,
            $meetingIssues->pluck('id'),
            $decisions->pluck('id'),
        );
    }

    /** @return array<int, array> */
    private function callLlm(
        CalendarEvent $event,
        Team $team,
        string $transcript,
        Collection $existingEpics,
        Collection $meetingIssues,
        Collection $decisions
    ): array {
        $orgContext = $team->organization?->context;
        $summaryText = $event->meetingSummary?->summary;

        $userPayload = [
            'meeting_title' => $event->title,
            'meeting_date'  => $event->starts_at->toDateString(),
            'summary_text'  => $summaryText,
            'decisions'     => $decisions->map(fn (Decision $d) => [
                'id'    => $d->id,
                'text'  => $d->text,
                'topic' => $d->topic,
            ])->values()->all(),
            'existing_epics' => $existingEpics->map(fn (Issue $e) => [
                'id'                  => $e->id,
                'name'                => $e->name,
                'description_excerpt' => mb_substr(strip_tags((string) $e->description), 0, 300),
            ])->values()->all(),
            'meeting_issues' => $meetingIssues->map(fn (Issue $i) => [
                'id'   => $i->id,
                'name' => $i->name,
            ])->values()->all(),
            'transcript' => $transcript,
        ];

        try {
            $json = $this->llm->chat(
                messages: [
                    new MessageDTO('system', $this->buildSystemPrompt($orgContext)),
                    new MessageDTO('user', json_encode($userPayload, JSON_UNESCAPED_UNICODE)),
                ],
                model: Setting::get('model.followup', config('ai.providers.openrouter.models.followup')),
                maxTokens: 4096,
                forceJsonResponse: true,
            );

            if (is_string($json) && preg_match('/\{[\s\S]*\}/s', $json, $matches)) {
                $json = $matches[0];
            }
            $decoded = is_string($json) ? json_decode($json, true) : $json;

            return $decoded['epics'] ?? [];
        } catch (\Throwable $e) {
            Log::error('EpicExtractionService: LLM call failed', [
                'calendar_event_id' => $event->id,
                'error'             => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function buildSystemPrompt(?string $orgContext): string
    {
        $contextSection = $orgContext
            ? "\n## Organization context\n\nUse this to understand the domain, team roles, terminology when identifying strategic goals:\n\n{$orgContext}\n"
            : '';

        return app(LlmPromptService::class)->renderView(
            slug: 'issue.epic_extraction.system',
            organizationId: null,
            fallbackView: 'llm-prompts.issue.epic-extraction-system',
            variables: ['context_section' => $contextSection],
            name: 'Epic extraction system prompt',
        );
    }
}
