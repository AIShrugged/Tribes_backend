<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Update model settings seeded by the original create_settings_table migration
 * to use native Anthropic model IDs now that OpenRouter has been replaced.
 */
return new class extends Migration
{
    private const MODEL_MAP = [
        // OpenRouter format => Anthropic native format
        'google/gemini-3-pro-preview'   => 'claude-sonnet-4-6',
        'google/gemini-3.1-pro-preview' => 'claude-sonnet-4-6',
        'anthropic/claude-3.5-sonnet'   => 'claude-sonnet-4-6',
        'anthropic/claude-sonnet-4.6'   => 'claude-sonnet-4-6',
        'openai/gpt-4o-mini'            => 'claude-haiku-4-5-20251001',
    ];

    public function up(): void
    {
        foreach (self::MODEL_MAP as $old => $new) {
            DB::table('settings')
                ->where('key', 'like', 'model.%')
                ->where('value', $old)
                ->update(['value' => $new, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // NOTE: This rollback is intentionally lossy.
        //
        // The `up()` migration maps multiple distinct OpenRouter model IDs
        // (google/gemini-3-pro-preview, google/gemini-3.1-pro-preview,
        // anthropic/claude-3.5-sonnet, anthropic/claude-sonnet-4.6) all to the
        // same Anthropic value (claude-sonnet-4-6). The original per-row model
        // cannot be recovered without a separate backup. This rollback restores
        // the most common pre-migration values as a best effort only.
        //
        // If you need a lossless rollback, restore from a database snapshot taken
        // before running this migration.
        DB::table('settings')
            ->where('key', 'like', 'model.%')
            ->where('value', 'claude-sonnet-4-6')
            ->update(['value' => 'google/gemini-3.1-pro-preview', 'updated_at' => now()]);

        DB::table('settings')
            ->where('key', 'like', 'model.%')
            ->where('value', 'claude-haiku-4-5-20251001')
            ->update(['value' => 'openai/gpt-4o-mini', 'updated_at' => now()]);
    }
};
