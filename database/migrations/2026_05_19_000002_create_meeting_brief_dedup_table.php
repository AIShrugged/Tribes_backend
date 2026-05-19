<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent dedup for pre-meeting briefs (group + personal). Replaces the
 * cache-based dedup in PreMeetingBriefService and PersonalPreMeetingBriefService
 * that lost state on Redis/container restarts and caused duplicate sends.
 *
 * `brief_kind`:
 *   - 'group'    → recipient_id is team_notification_settings.id
 *   - 'personal' → recipient_id is users.id
 *
 * No foreign key on recipient_id by design — it points at different tables
 * depending on brief_kind, and FK semantics differ. Cascade on event delete only.
 *
 * Unique (calendar_event_id, brief_kind, recipient_id) is the atomic dedup
 * mechanism: a concurrent second INSERT throws UniqueConstraintViolationException.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_brief_dedup', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->string('brief_kind', 30);
            $table->unsignedBigInteger('recipient_id');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(
                ['calendar_event_id', 'brief_kind', 'recipient_id'],
                'meeting_brief_dedup_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_brief_dedup');
    }
};
