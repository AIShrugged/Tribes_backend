<?php

namespace App\Services\TaskData;

use App\Domain\DTO\AI\MessageDTO;
use App\Exceptions\ContentNotRelevantException;
use App\Models\Setting;
use App\Models\TaskDataUpload;
use App\Models\Team;
use App\Models\User;
use App\Services\Issue\TaskDataUploadSourceContext;
use App\Services\IssueMergeService;
use App\Services\OpenRouterClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Extracts issues from a text document (any format — meeting notes, TODO list,
 * task descriptions, protocols) via LLM call, then persists via IssueMergeService
 * with LLM-based deduplication against existing open issues.
 *
 * Unlike IssueExtractionService (meeting-transcript-centric), this service uses
 * a document-oriented prompt that doesn't assume speaker:text format or meeting context.
 */
class TaskDataIssueExtractionService
{
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly IssueMergeService $issueMerge,
    ) {
    }

    /**
     * @return array{created: Collection, updated: Collection}
     */
    /**
     * Step 1: LLM call — extract raw issue items from text.
     * Separated from persist so the caller can update status between steps.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractItems(string $text, TaskDataUpload $upload, Team $team): array
    {
        if (blank($text)) {
            return [];
        }

        $orgContext = $team->organization?->context;

        $messages = [
            new MessageDTO('system', $this->buildSystemPrompt($orgContext)),
            new MessageDTO('user',
                "Source: {$upload->original_filename}\n"
                . "Current date: " . now()->toDateString() . "\n\n"
                . "Document content:\n" . $text
            ),
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

            if (isset($decoded['is_work_relevant']) && $decoded['is_work_relevant'] === false) {
                throw new ContentNotRelevantException();
            }

            $items = $decoded['issues'] ?? [];
        } catch (ContentNotRelevantException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Task data issue extraction failed', [
                'upload_id' => $upload->id,
                'team_id'   => $team->id,
                'error'     => $e->getMessage(),
            ]);
            return [];
        }

        return array_values(array_filter($items, fn ($item) => trim($item['name'] ?? '') !== ''));
    }

    /**
     * Step 2: Persist extracted items via IssueMergeService (LLM dedup + create/update/skip).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{created: \Illuminate\Support\Collection, updated: \Illuminate\Support\Collection}
     */
    public function persistItems(array $items, Team $team, User $user, TaskDataUpload $upload): array
    {
        $ctx = new TaskDataUploadSourceContext($upload);
        $result = $this->issueMerge->persistFromSource($items, $team, $user, $ctx);

        Log::info('Tasks extracted from uploaded data', [
            'upload_id' => $upload->id,
            'team_id'   => $team->id,
            'created'   => $result['created']->count(),
            'updated'   => $result['updated']->count(),
        ]);

        return $result;
    }

    /**
     * COMPUTE half (pre-moderation): run the merge-LLM dedup against existing open issues but write
     * NOTHING. Returns the storable plan section {items, decisions, existing_snapshots}. The approve
     * handler later replays IssueMergeService::applyPlanFromSource() to materialize the rows.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{items: array<int, array>, decisions: ?array, existing_snapshots: array<int, array>}
     */
    public function computePlan(array $items, Team $team, TaskDataUpload $upload): array
    {
        $ctx = new TaskDataUploadSourceContext($upload);

        return $this->issueMerge->computePlanFromSource($items, $team, $ctx);
    }

    private function buildSystemPrompt(?string $orgContext = null): string
    {
        $contextSection = $orgContext
            ? "\n## Organization context\n\nUse this to better understand the domain, team roles, and terminology when extracting tasks:\n\n{$orgContext}\n"
            : '';

        return <<<PROMPT
You are an AI assistant that extracts actionable tasks from text documents.{$contextSection}

The input document may be meeting notes, a TODO list, project update, email thread, task description, protocol, or any other text containing information about work to be done.

## Relevance check

First, evaluate whether the document is related to this organization's work activity.

Set "is_work_relevant" to false if the document is:
- Personal/non-work content: recipes, diaries, fiction, personal chats, shopping lists, entertainment
- Clearly from a completely different organization with no connection to the context described above

Set "is_work_relevant" to true for:
- Any work-related document: meeting notes, tasks, project updates, protocols, email threads, backlogs, etc.
- When uncertain — default to true

## Task extraction

Your goal is to find concrete tasks, assignments, decisions, and action items. Do NOT invent tasks that aren't in the text. Extract ONLY what is explicitly stated or clearly implied.

## What IS a task:
- "Fix the auth bug by Friday" → task
- "Alice will handle the deployment" → task (assignee: Alice)
- "We need to migrate the database" → task
- "TODO: update API docs" → task
- Numbered action items, bullet points with assignments

## What is NOT a task:
- General commentary ("the meeting went well")
- Already completed actions ("we fixed that yesterday")
- Vague aspirations without concrete actions ("we should think about it someday")

## Response format

Return JSON strictly in this format:
{
  "is_work_relevant": true,
  "issues": [
    {
      "name": "Verb + what exactly to do (up to 80 characters)",
      "description": "## Context\nWhy this is needed.\n\n## Steps\n1. Step 1\n2. Step 2\n\n## Definition of done\nHow to know the task is complete.",
      "type": "frontend | backend | organization",
      "author_name": "First Last | null",
      "assignee_name": "First Last | null",
      "due_date": "YYYY-MM-DD | null",
      "priority": "critical | high | normal | low | minimal"
    }
  ]
}

## Field rules

**name** — start with a verb: "Fix...", "Add...", "Configure...", "Investigate...". Must be clear WHAT to do without reading the description.

**description** — three sections: Context (1-3 sentences), Steps (numbered list), Definition of done (one sentence).

**type**: "frontend" (UI/web), "backend" (APIs/services/data), "organization" (coordination/process).

**author_name** — person who formulated/proposed the task. If unclear — null.

**assignee_name** — person explicitly assigned. If unclear — null.

**due_date** — only if a deadline is explicitly mentioned. Convert relative dates from today. If none — null.

**priority**: "critical" (blocker/urgent), "high" (ASAP), "normal" (default), "low" (nice-to-have), "minimal" (someday).

## Important
- Do not duplicate: same task mentioned twice = one issue
- Do not split a single task into micro-steps
PROMPT;
    }
}
