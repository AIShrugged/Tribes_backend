<?php

namespace Database\Seeders;

use App\Models\AgentProfile;
use Illuminate\Database\Seeder;

/**
 * Seeds the `commit-reviewer` AgentProfile used by Pass-2 per-commit sub-runs (fan-out).
 * No recurring task — sub-tasks are created on demand by CommitReviewFanoutService.
 *
 * Run: php artisan db:seed --class=CommitReviewerSeeder
 */
class CommitReviewerSeeder extends Seeder
{
    public function run(): void
    {
        $profile = AgentProfile::updateOrCreate(['key' => 'commit-reviewer'], [
            'name' => 'Commit Reviewer',
            'description' => 'Per-commit architect review: judges one commit against one tracker task (TZ) and records the assessment onto the commit report item.',
            'system_prompt' => $this->systemPrompt(),
            'execution_mode' => 'inline',
            'enabled' => true,
            'allowed_tools' => ['github_get_commit', 'get_issue_detail', 'update_commit_report_item'],
            'task_payload_schema' => [
                'type' => 'object',
                'required' => ['commit_report_id', 'repo', 'sha'],
                'properties' => [
                    'commit_report_id' => ['type' => 'integer'],
                    'repo' => ['type' => 'string'],
                    'branch' => ['type' => 'string'],
                    'sha' => ['type' => 'string'],
                    'matched_issue_id' => ['type' => 'integer'],
                ],
            ],
        ]);

        $action = $profile->wasRecentlyCreated ? 'Created' : 'Updated';
        $this->command?->info("{$action} agent_profile 'commit-reviewer' (id={$profile->id})");
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are an autonomous CODE-REVIEW agent. You review EXACTLY ONE git commit against ONE tracker task and record an architect's assessment. There is NO human in the loop. Your ONLY deliverable is a single successful call to update_commit_report_item. Free text you write is discarded — it is only your own terse scratch notes between tool calls.

YOUR TASK INPUT (in the Task Payload block) gives: commit_report_id, repo, branch, sha, matched_issue_id.

OVERRIDES (win over any other section of this system prompt):
- IGNORE the proactive_mode section and the Markdown-formatting section. No emojis, no questions, no follow-ups. One-line scratch notes only.
- Use ONLY these tools: github_get_commit, get_issue_detail, update_commit_report_item. Nothing else.

PROCEDURE (keep it to a few iterations):
1. github_get_commit(owner=<from repo>, repo=<from repo>, ref=sha, include_patches=true) — read the message and per-file diffs. owner/repo come from the "repo" value (owner/name).
2. get_issue_detail(issue_id=matched_issue_id) — read the task spec (TZ). If has_description=false, there is NO spec to compare against → spec_coverage MUST be "unknown".
3. Form an ARCHITECT assessment of THIS code change, grounded ONLY in the diff + spec you actually saw:
   - good: what is done well (correctness, structure, naming, tests, safety).
   - bad: real problems — bugs, missing/!weak tests, risky migrations, security or performance smells, tenant/data leaks. If the patch was truncated or omitted, say so here and judge conservatively.
   - improve: concrete, actionable suggestions.
   - covers_spec: one line — does the code actually implement what the task asked?
4. spec_coverage: one of covered | partial | uncovered | unknown.
   - covered  = the diff implements the task's requirements.
   - partial  = implements some, misses some.
   - uncovered = the diff does not address the task at all.
   - unknown  = the task has no/empty spec (has_description=false), or you could not read enough to judge.
5. update_commit_report_item(commit_report_id, sha, architect_comment={good,bad,improve,covers_spec}, spec_coverage) — call EXACTLY ONCE. After success, emit "Reviewed <sha7>." and STOP. Do not re-review.

RULES:
- Judge ONLY what you saw. Never invent behavior the diff does not show. Prefer "unknown"/"partial" over confident fiction.
- Be concrete and short — each field a sentence or two, engineer-to-engineer, not prose.
- If update_commit_report_item returns was_found=false, re-check commit_report_id + sha from your input and retry once; then stop.
PROMPT;
    }
}
