<?php

namespace App\Services;

use App\Domain\DTO\AI\MessageDTO;
use App\Models\AgentActivityLog;
use App\Models\CalendarEvent;
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
        private readonly IssueMergeService $issueMerge,
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

        $orgContext = $team->organization?->context;

        $messages = [
            new MessageDTO('system', $this->buildSystemPrompt($orgContext)),
            new MessageDTO('user', "Дата встречи: {$event->starts_at->toDateString()}\nТекущая дата: ".now()->toDateString()."\n\nТранскрипт встречи:\n".$transcript),
        ];

        try {
            $json = $this->llm->chat(
                messages: $messages,
                model: Setting::get('model.followup', config('ai.providers.openrouter.models.followup')),
                maxTokens: 4096,
                forceJsonResponse: true,
            );

            if (is_string($json) && preg_match('/\{[\s\S]*\}/s', $json, $matches)) {
                $json = $matches[0];
            }
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

        $items = array_values(array_filter($items, fn ($item) => trim($item['name'] ?? '') !== ''));

        $issues = $this->issueMerge->persist($items, $event, $team, $user);

        Log::info('Issues extracted from transcript', [
            'calendar_event_id' => $event->id,
            'team_id' => $team->id,
            'count' => $issues->count(),
        ]);

        AgentActivityLog::recordActivity(
            user: $user,
            toolName: 'issues_extracted',
            toolResult: [
                'count' => $issues->count(),
                'calendar_event_id' => $event->id,
                'team_id' => $team->id,
            ],
            toolArgs: [
                'calendar_event_id' => $event->id,
                'team_id' => $team->id,
            ],
        );

        return $issues;
    }

    private function buildSystemPrompt(?string $orgContext = null): string
    {
        $contextSection = $orgContext
            ? "\n## Organization context\n\nUse this to better understand the domain, team roles, and terminology when extracting tasks:\n\n{$orgContext}\n"
            : '';

        return <<<PROMPT
You are an AI assistant that extracts concrete tasks from work meeting transcripts.{$contextSection}

Your goal is to find real commitments and decisions the team made during the meeting. Do NOT invent tasks, do NOT generalize discussions into tasks. Extract ONLY what someone explicitly committed to or what the team explicitly decided to do.

## How to distinguish a task from a discussion

✅ IS a task — when someone said:
- "I'll do...", "I'll take care of...", "Let me look into..."
- "We need this by Friday...", "By the next meeting..."
- "Create a ticket for...", "Open a PR for..."
- A concrete decision with an assignee: "Pete, handle the..."

❌ NOT a task:
- General discussions ("we'll think about it", "we should someday")
- Unresolved questions ("what if we...?")
- Status updates ("I checked yesterday, everything works")
- Already completed actions ("we already fixed that")

## Response format

Return JSON strictly in the following format:
{
  "issues": [
    {
      "name": "Verb + what exactly to do (up to 80 characters)",
      "description": "## Context\nWhy this is needed — what was discussed at the meeting, what problem exists.\n\n## Steps\n1. Concrete step 1\n2. Concrete step 2\n\n## Definition of done\nHow to know the task is complete.",
      "type": "frontend | backend | organization",
      "assignee_name": "First Last | null",
      "due_date": "YYYY-MM-DD | null",
      "priority": "critical | high | normal | low | minimal"
    }
  ]
}

## Field rules

**name** — start with a verb: "Fix...", "Add...", "Configure...", "Investigate...". It must be clear WHAT to do without reading the description.

**description** — must contain three sections:
- "Context" — 1-3 sentences: why the task arose, what was discussed. Quote key phrases from the transcript.
- "Steps" — numbered list of concrete actions. Not "look into it", but "check logs for the past week", "update config X".
- "Definition of done" — one sentence: what the outcome should be (PR created, metric improved, document written).

**type**:
- "frontend" — UI, web app, client-side work
- "backend" — APIs, services, infrastructure, data, integrations
- "organization" — coordination, process, operations, or non-implementation work

**assignee_name** — the name of the person who EXPLICITLY took the task or was EXPLICITLY assigned it in the conversation. If unclear — null.

**due_date** — only if a deadline was EXPLICITLY mentioned in the meeting ("by Friday", "by April 1st", "next week"). Convert relative dates from the meeting date. If no deadline was mentioned — null.

**priority** — choose based on what was said:
- "critical" — explicit blockers, prod down, "drop everything else", "must be done today"
- "high" — explicit urgency: "ASAP", "before the next meeting", customer-facing release
- "normal" — default for any task without explicit urgency markers (use this if unsure)
- "low" — explicit "when you have time", "nice to have", post-release polish
- "minimal" — explicit "someday/maybe", parking lot, ideas to revisit

If the meeting did not signal urgency — use "normal".

## Important
- Do not duplicate: if the same task was discussed multiple times — it is one task
- Do not split a single task into micro-steps — steps go in the description
PROMPT;
    }
}
