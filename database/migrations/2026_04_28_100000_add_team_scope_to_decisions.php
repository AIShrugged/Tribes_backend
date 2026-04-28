<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decisions', function (Blueprint $table) {
            // Drop the original cascadeOnDelete FK so we can re-add it as nullOnDelete.
            // Manual/chat decisions (calendar_event_id = NULL) must survive event deletion.
            $table->dropForeign(['calendar_event_id']);
            $table->unsignedBigInteger('calendar_event_id')->nullable()->change();
            $table->foreign('calendar_event_id')->references('id')->on('calendar_events')->nullOnDelete();

            $table->unsignedBigInteger('team_id')->nullable()->after('summary_id');
            $table->unsignedBigInteger('organization_id')->nullable()->after('team_id');
            $table->string('source_type')->default('meeting')->after('organization_id');

            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();

            $table->index(['team_id', 'created_at'], 'decisions_team_id_created_at_index');
            $table->index(['organization_id', 'created_at'], 'decisions_organization_id_created_at_index');
        });

        // CHECK constraint so the DB rejects invalid source_type values independently of PHP.
        DB::statement("ALTER TABLE decisions ADD CONSTRAINT decisions_source_type_check CHECK (source_type IN ('meeting', 'manual', 'chat'))");

        // Use 'simple' dictionary (no language-specific stemming) so both Russian and
        // English text is indexed and searched without stemming loss.
        DB::statement("ALTER TABLE decisions ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('simple', coalesce(text, '') || ' ' || coalesce(topic, '') || ' ' || coalesce(author_raw_name, ''))) STORED");

        DB::statement('CREATE INDEX decisions_search_vector_gin ON decisions USING GIN (search_vector)');
    }

    public function down(): void
    {
        // Drop Schema-managed objects first so a partial failure doesn't leave
        // orphaned FKs or columns.
        Schema::table('decisions', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropForeign(['organization_id']);
            $table->dropIndex('decisions_team_id_created_at_index');
            $table->dropIndex('decisions_organization_id_created_at_index');
            $table->dropColumn(['team_id', 'organization_id', 'source_type']);

            // Restore original nullable=false + cascadeOnDelete FK on calendar_event_id.
            $table->dropForeign(['calendar_event_id']);
            $table->unsignedBigInteger('calendar_event_id')->nullable(false)->change();
            $table->foreign('calendar_event_id')->references('id')->on('calendar_events')->cascadeOnDelete();
        });

        // Raw SQL last — these are IF EXISTS so safe even on partial rollback.
        DB::statement('ALTER TABLE decisions DROP CONSTRAINT IF EXISTS decisions_source_type_check');
        DB::statement('DROP INDEX IF EXISTS decisions_search_vector_gin');
        DB::statement('ALTER TABLE decisions DROP COLUMN IF EXISTS search_vector');
    }
};
