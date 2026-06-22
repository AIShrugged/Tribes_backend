<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Human-in-the-loop proposals from the "second brain". The brain no longer
 * mutates the product directly; it proposes an ACTION here (key + a small,
 * stable domain "intent" payload). A human approves, and the backend applies it
 * deterministically via SuggestionApplier (no LLM/loop wake needed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brain_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('run_uuid', 64)->nullable();           // which loop pass proposed it

            // The action.
            $table->string('key');                                // create_issue | update_task_status
            $table->unsignedSmallInteger('payload_version')->default(1);
            $table->jsonb('payload');                             // stable domain intent (NOT raw tool args)

            // Human-facing rationale.
            $table->string('title');
            $table->text('summary')->nullable();
            $table->text('reasoning')->nullable();
            $table->jsonb('evidence')->nullable();                // {meeting_id, decision_id, issue_id, quote, ...}
            $table->unsignedTinyInteger('confidence')->nullable();

            // Idempotency: the brain re-proposes each pass; this prevents duplicates.
            $table->string('dedupe_key');

            // Lifecycle.
            $table->string('status')->default('pending');         // pending|approved|applied|rejected|failed|superseded|expired
            $table->jsonb('applied_result')->nullable();          // e.g. {issue_id: 962}
            $table->text('failure_reason')->nullable();

            // What it points at (Issue / Decision / CalendarEvent).
            $table->nullableMorphs('subjectable');

            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'dedupe_key']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brain_suggestions');
    }
};
