<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extraction_plans', function (Blueprint $table) {
            $table->id();

            // Polymorphic source: App\Models\CalendarEvent (transcript) | App\Models\TaskDataUpload (file).
            // FQCN strings (no morphMap enforced in this project). morphs() adds the (type,id) index;
            // the unique() below also covers (type,id) lookups, so we do NOT add a third overlapping index.
            $table->string('sourceable_type');
            $table->unsignedBigInteger('sourceable_id');

            // Resolved at eager-create time. Nullable: transcript team is heuristic and decisions are
            // team-agnostic until apply (fan-out happens in ExtractDecisionsService at approve).
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            // Uploader / row owner. Always known (creator_user_id for transcript, upload.user_id for task-data).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // collecting (transcript, awaiting all sections) | pending_review | approved | rejected | failed
            $table->string('status')->default('collecting');

            $table->jsonb('plan')->nullable();              // editable payload: {issues:{...}, decisions:{...}, review:{review_id}}
            $table->jsonb('expected_sections')->nullable(); // computed at create, e.g. ["decisions","issues"] or ["decisions"]
            $table->jsonb('section_status')->nullable();    // {"issues":"ready|failed","decisions":"ready|failed"}

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // One logical plan per source. Serves as the lookup index too (no separate morphs index needed).
            $table->unique(['sourceable_type', 'sourceable_id'], 'extraction_plans_sourceable_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extraction_plans');
    }
};
