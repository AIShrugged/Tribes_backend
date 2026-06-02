<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcript_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Nullable: org is resolved from the event on success; a failed-before-event
            // row stays visible to its uploader without a NOT NULL violation dropping it.
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            // nullOnDelete (NOT cascade): CalendarEvent is hard-deleted (SourceDetachService,
            // demo/seed); cascade would destroy the audit row D2 exists to preserve.
            $table->foreignId('calendar_event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_filename');
            $table->string('status')->default('pending'); // pending|done|failed
            $table->unsignedInteger('transcript_entries_count')->nullable();
            $table->unsignedInteger('participants_count')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcript_uploads');
    }
};
