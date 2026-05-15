<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-6.10: direct link from an issue to the protocol item that spawned it.
 *
 * "Protocol item" in path A == decisions.id — we already track many-to-many
 * via decision_issue, but US-6.10 expects a direct field on the issue so the
 * meeting dashboard can render `protocol item → its task`. The pivot stays as
 * the source of truth for multi-coverage; this column carries the canonical
 * "this issue exists because of THAT decision" attribution for the first link.
 *
 * Populated by VerifyMeetingArtifactsJob alongside decision_issue inserts.
 * Older rows stay null (no historical attribution to backfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->foreignId('source_protocol_item_id')
                ->nullable()
                ->after('epic_id')
                ->constrained('decisions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropForeign(['source_protocol_item_id']);
            $table->dropColumn('source_protocol_item_id');
        });
    }
};
