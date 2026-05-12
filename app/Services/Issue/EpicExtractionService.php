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
            $meetingIssues->pluck('id')
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

        return <<<PROMPT
You extract STRATEGIC GOALS (epics) from work meeting transcripts. Epics are long-term, multi-task objectives — NOT individual tasks.{$contextSection}

## What IS an epic
- A goal that requires multiple tasks (issues) to accomplish.
- Spoken about as a direction, a milestone, an objective. Examples:
  - "By the end of Q2, we want to have ROI tracking for all campaigns" → epic.
  - "Let's rebuild the onboarding flow to reduce churn" → epic.
  - "Make Wanda the de-facto HR tool inside our org" → epic.

## What is NOT an epic (do not create or update an epic for these)
- A single concrete task. "Fix the auth bug" → issue, not an epic.
- A discussion item without commitment. "We should think about X someday" → skip.
- A status update or progress report.

## What you receive (user message)
- `meeting_title`, `meeting_date`, `summary_text`
- `decisions[]` — explicit decisions from the meeting (id, text, topic).
- `existing_epics[]` — currently open epics in the organization (id, name, description_excerpt). Use these to decide create/update/skip.
- `meeting_issues[]` — tasks created from THIS meeting (id, name). You may link these to an epic via `child_issue_ids`.
- `transcript` — full meeting transcript with speakers.

## Output format

Return JSON strictly in this format:
```
{
  "epics": [
    {
      "action": "create | update | skip",
      "existing_epic_id": null,
      "name": "Short goal formulation (up to 80 chars)",
      "description": "## Контекст\\nЧто было обсуждено, какая бизнес-причина.\\n\\n## Пункты\\n1. Конкретный пункт 1\\n2. Конкретный пункт 2",
      "scope": "team | organization",
      "author_name": "First Last | null",
      "child_issue_ids": [123, 456],
      "update_description": "При action=update — что нового добавилось"
    }
  ]
}
```

## Field rules

**action**:
- "create" — genuinely new strategic goal not covered by existing epics.
- "update" — same goal as one of `existing_epics`, with new context/scope/items from this meeting. Set `existing_epic_id` to the matching id.
- "skip" — goal already fully covered, nothing new to add. Use this when in doubt to avoid noise.

**name** — short, direction-oriented. Avoid verbs like "fix" or "add" (those are tasks); prefer outcome-oriented phrasing: "ROI tracking for campaigns", "Rebuild onboarding flow".

**description** — markdown with TWO sections only:
- `## Контекст` — 1-3 sentences: why this goal exists, what business problem it addresses, what was said at the meeting. Quote key phrases if useful.
- `## Пункты` — numbered list of high-level steps or sub-areas. NOT a granular task list (that's what `meeting_issues` are for).
- DO NOT include a Definition of Done section — epics are open-ended objectives.

**scope** (criteria — be strict):
- "team" (default) — the goal is within the responsibility of ONE team; resources come from that team.
- "organization" — explicit signals: cross-team, company-wide, executive-sponsored, strategic at the org level. Use only when the transcript explicitly indicates a company-wide initiative.

**author_name** — name of the speaker who FORMULATED or PROPOSED this goal in the transcript. The owner of the vision, not the assignee. If unclear — null.

**child_issue_ids** — array of ids from `meeting_issues[]` that LOGICALLY belong under this epic (they are concrete steps toward this goal). Empty array if none.

**update_description** (only when action=update) — 1-3 sentences describing what NEW context this meeting added to the existing epic. Do not repeat existing description.

## Critical rules
- Do not invent epics. If the meeting was purely tactical (bug fixes, status updates), return an empty array `epics: []`.
- Each existing_epic appears in your output at most once (either update or skip — not both).
- existing_epic_id must come from `existing_epics[]`. Do NOT invent ids.
- child_issue_ids must come from `meeting_issues[]`. Do NOT invent ids.
- Prefer "skip" over speculative "update" — only update when there's concrete new information.
PROMPT;
    }
}
