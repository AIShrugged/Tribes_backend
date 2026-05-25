<?php

use App\Services\LlmPromptDefaultRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('llm_prompts')) {
            return;
        }

        $viewsBySlug = collect(app(LlmPromptDefaultRegistry::class)->all())
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
            DB::table('llm_prompts')
                ->where('slug', $slug)
                ->where('prompt', '{prompt_body}')
                ->update([
                    'prompt' => app(LlmPromptDefaultRegistry::class)->render($view),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('llm_prompts')) {
            return;
        }

        DB::table('llm_prompts')
            ->whereIn('slug', [
                'agent.system',
                'agenda.general.user',
                'agenda.meeting_series_state.user',
                'agenda.personal.user',
                'agenda.upcoming.user',
                'chat.wanda.system',
            ])
            ->whereIn('prompt', [
                '{sections}',
                '{system_prompt}',
            ])
            ->update([
                'prompt' => '{prompt_body}',
                'updated_at' => now(),
            ]);
    }
};
