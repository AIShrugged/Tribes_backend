<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove duplicate rows — keep only the most recently updated row per (profile_id, context_type).
        // This fixes a pre-existing race condition in UpdateMemoryTool::execute().
        DB::statement("
            DELETE FROM insight_short_term
            WHERE id NOT IN (
                SELECT DISTINCT ON (profile_id, context_type) id
                FROM insight_short_term
                ORDER BY profile_id, context_type, updated_at DESC
            )
        ");

        Schema::table('insight_short_term', function (Blueprint $table) {
            $table->dropIndex('insight_short_term_profile_id_context_type_expires_at_index');
            $table->unique(['profile_id', 'context_type'], 'insight_short_term_profile_context_unique');
            $table->index(['profile_id', 'expires_at'], 'insight_short_term_profile_expires_index');
        });

        // Partial index for active records — eliminates expired row scan in scopeActive() queries.
        DB::statement("
            CREATE INDEX insight_short_term_active_idx
            ON insight_short_term (profile_id, context_type, updated_at DESC)
            WHERE expires_at > NOW()
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS insight_short_term_active_idx');

        Schema::table('insight_short_term', function (Blueprint $table) {
            $table->dropUnique('insight_short_term_profile_context_unique');
            $table->dropIndex('insight_short_term_profile_expires_index');
            $table->index(['profile_id', 'context_type', 'expires_at']);
        });
    }
};