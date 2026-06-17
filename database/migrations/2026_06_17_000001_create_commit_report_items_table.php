<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commit_report_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commit_report_id')->constrained('commit_reports')->cascadeOnDelete();
            $table->string('sha', 40);
            $table->string('bucket', 16);            // 'added' | 'fixed'
            $table->string('title')->nullable();
            $table->text('summary')->nullable();
            $table->unsignedInteger('position')->default(0);

            // Pass-1 matching (owned by Pass 1)
            $table->foreignId('matched_issue_id')->nullable()->constrained('issues')->nullOnDelete();
            $table->string('matched_issue_name')->nullable();      // point-in-time snapshot
            $table->string('matched_confidence', 16)->nullable();  // high|medium|low
            $table->string('match_source', 16)->nullable();        // explicit|semantic
            $table->boolean('unmatched')->default(true);
            $table->text('evidence')->nullable();
            $table->json('related_issues')->nullable();            // point-in-time [{id,name}] snapshot

            // Pass-2 deep review (owned by Pass 2 write-back ONLY)
            $table->json('architect_comment')->nullable();         // {good,bad,improve,covers_spec}
            $table->string('spec_coverage', 32)->nullable();       // covered|partial|uncovered|unknown
            // pending=dispatched/awaiting; in_progress; done; failed;
            // deferred=matched but over the per-report cap; not_flagged=unmatched (never reviewed)
            $table->string('review_status', 24)->default('pending');

            $table->timestamps();

            $table->unique(['commit_report_id', 'sha'], 'commit_report_items_report_sha_unique');
            $table->index(['commit_report_id', 'review_status']);
            $table->index('matched_issue_id');
            $table->index('unmatched');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commit_report_items');
    }
};
