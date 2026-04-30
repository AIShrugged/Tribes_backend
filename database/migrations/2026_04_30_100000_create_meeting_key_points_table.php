<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_key_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_summary_id')
                  ->constrained('meeting_summaries')
                  ->cascadeOnDelete();
            $table->foreignId('calendar_event_id')
                  ->nullable()
                  ->constrained('calendar_events')
                  ->nullOnDelete();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->text('text');
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->index(['team_id', 'created_at'], 'mkp_team_id_created_at_index');
            $table->index(['organization_id', 'created_at'], 'mkp_organization_id_created_at_index');
            $table->timestamps();
        });

        DB::statement(
            "ALTER TABLE meeting_key_points ADD COLUMN search_vector tsvector
             GENERATED ALWAYS AS (to_tsvector('simple', coalesce(text, ''))) STORED"
        );

        DB::statement(
            'CREATE INDEX meeting_key_points_search_gin ON meeting_key_points USING GIN (search_vector)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS meeting_key_points_search_gin');
        Schema::dropIfExists('meeting_key_points');
    }
};