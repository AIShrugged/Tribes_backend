<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('conflict_group_uuid');
            $table->foreignId('issue_id')->constrained('issues')->cascadeOnDelete();
            $table->string('field', 32); // requirements | due_date | assignee
            $table->text('conflict_summary');
            $table->foreignId('detected_in_calendar_event_id')->nullable()
                ->constrained('calendar_events')->nullOnDelete();
            $table->string('status', 16)->default('open'); // open | resolved | ignored
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['conflict_group_uuid', 'issue_id', 'field']);
            $table->index('issue_id');
            $table->index(['detected_in_calendar_event_id', 'status']);
            $table->index('conflict_group_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_conflicts');
    }
};
