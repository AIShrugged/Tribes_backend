<?php

use App\Services\LlmPromptDefaultRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration 2026_05_25_000002 replaced the legacy `{prompt_body}` placeholder with
 * the rendered default prompts, but matched the column exactly against the string
 * `'{prompt_body}'`. The Blade views render with a trailing newline, so the stored
 * value was actually "{prompt_body}\n" and never matched — leaving the literal
 * placeholder in the DB. At runtime LlmPromptService::render() substitutes
 * `{sections}` / `{system_prompt}`, which are absent from the stale template, so the
 * raw "{prompt_body}" string was being sent to the LLM (breaking GenerateUpcomingAgendaJob
 * and every other feature backed by these slugs).
 *
 * This re-runs the replacement with a whitespace-robust match.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('llm_prompts')) {
            return;
        }

        $registry = app(LlmPromptDefaultRegistry::class);

        $viewsBySlug = collect($registry->all())
            ->whereIn('slug', [
                'agent.system',
                'agenda.general.user',
                'agenda.meeting_series_state.user',
                'agenda.personal.user',
                'agenda.upcoming.user',
                'chat.wanda.system',
            ])
            ->pluck('view', 'slug');

        foreach ($viewsBySlug as $slug => $view) {
            // Only touch rows whose entire (trimmed) content is the bare legacy
            // placeholder — org-specific customised prompts are left untouched.
            $staleIds = DB::table('llm_prompts')
                ->where('slug', $slug)
                ->where('prompt', 'like', '%{prompt_body}%')
                ->get(['id', 'prompt'])
                ->filter(fn ($row) => trim($row->prompt) === '{prompt_body}')
                ->pluck('id');

            if ($staleIds->isEmpty()) {
                continue;
            }

            DB::table('llm_prompts')
                ->whereIn('id', $staleIds)
                ->update([
                    'prompt' => $registry->render($view),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // No-op: reverting to the broken `{prompt_body}` placeholder is undesirable.
    }
};
