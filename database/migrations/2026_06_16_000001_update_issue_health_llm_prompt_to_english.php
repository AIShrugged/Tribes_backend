<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SLUG = 'issue.health.analysis.user';

    private const PROMPT = <<<'PROMPT'
You are a team management assistant. Analyze the task backlog state and write a brief report.

Team: {team_name}
Analysis date: {analysis_date}

Analysis data:
{findings}

Write an analytical summary in English (3–5 sentences):
- Assess the overall health of the team's backlog
- Highlight the most critical issues
- Point out what needs immediate attention

Plain text only — no headings, no JSON.
PROMPT;

    public function up(): void
    {
        DB::table('llm_prompts')
            ->where('slug', self::SLUG)
            ->update(['prompt' => self::PROMPT]);
    }

    public function down(): void
    {
        // Intentionally left blank — no safe way to restore the previous text.
    }
};
