<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Propagate the Phase-C "code changelog / commits" tool guidance into the DB-stored
 * llm_prompts overrides of `agent.section.tool_guidance`. LlmPromptService::resolve()
 * returns a stored row when present (the Blade is only a fallback for slugs with NO row),
 * so the Blade edit alone would never reach orgs that already have a row (global or custom).
 *
 * Idempotent: appends only to rows that don't already carry the marker. Appends (never
 * replaces) so per-org customizations survive. Fresh installs pick the block up from the
 * updated Blade when resolve() first materializes the global row.
 */
return new class extends Migration
{
    private const SLUG = 'agent.section.tool_guidance';

    private const MARKER = '## Code changelog / commits';

    public function up(): void
    {
        $block = "\n\n".$this->block();

        $rows = DB::table('llm_prompts')
            ->where('slug', self::SLUG)
            ->where('prompt', 'not like', '%'.self::MARKER.'%')
            ->get(['id', 'prompt']);

        foreach ($rows as $row) {
            DB::table('llm_prompts')->where('id', $row->id)->update([
                'prompt' => $row->prompt.$block,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $block = "\n\n".$this->block();

        $rows = DB::table('llm_prompts')
            ->where('slug', self::SLUG)
            ->where('prompt', 'like', '%'.self::MARKER.'%')
            ->get(['id', 'prompt']);

        foreach ($rows as $row) {
            DB::table('llm_prompts')->where('id', $row->id)->update([
                'prompt' => str_replace($block, '', $row->prompt),
                'updated_at' => now(),
            ]);
        }
    }

    private function block(): string
    {
        return <<<'TXT'
## Code changelog / commits
- Accessible repos (read-only): AIShrugged/Tribes_backend (backend, branch dev) and AIShrugged/Tribes_frontend (frontend, branch master). These are the ONLY repos you can read; if the user names another repo, say it is not connected.
- "what changed / what was added or fixed / recent commits": call get_last_commit_report first (latest saved changelog + scan window), then github_list_commits for newer commits; github_get_commit(sha) only when a commit is ambiguous.
- "is there a task/issue for this commit?": use get_issue_candidates, then search_issues_by_text, then get_issue_detail to read the spec. Match at most one issue; prefer no match over a weak guess.
- You are READ-ONLY here: never create or modify changelog reports.
TXT;
    }
};
