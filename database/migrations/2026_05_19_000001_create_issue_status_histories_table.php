<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit of every Issue status transition. Powers cycle-time analytics:
 *   cycle_time = MAX(changed_at where to_status='done') − MIN(changed_at where to_status='in_progress')
 *
 * Population: IssueObserver::updated() writes a row when `isDirty('status')`.
 * Legacy issues (created before this migration) do not back-fill — cycle time
 * is reported only for issues that transitioned through the observer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained('issues')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->timestamp('changed_at');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['issue_id', 'changed_at'], 'ish_issue_changed_idx');
            $table->index(['to_status', 'changed_at'], 'ish_status_changed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_status_histories');
    }
};
