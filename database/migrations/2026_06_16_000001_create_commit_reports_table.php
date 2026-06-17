<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commit_reports', function (Blueprint $table) {
            $table->id();

            // Source identity / idempotency key
            $table->string('repo');                 // e.g. 'AIShrugged/Tribes_backend'
            $table->string('branch');               // e.g. 'dev'
            $table->date('period_start');           // inclusive first calendar date covered (UTC)
            $table->date('period_end');             // inclusive last calendar date covered (UTC) = watermark

            // Content
            $table->text('summary')->nullable();    // AI narrative
            $table->json('items')->default('{}');   // {added:[], fixed:[], skipped:[]}
            $table->unsignedInteger('commit_count')->default(0);    // significant commits CONSIDERED
            $table->unsignedInteger('total_in_window')->default(0); // commits SEEN in the window (pre-filter)
            $table->json('commit_shas')->nullable(); // canonical scanned set (full SHAs)
            $table->string('source')->default('github');
            $table->string('status')->default('done'); // done | empty | partial

            // Provenance (nullable: web/test callers have no run)
            $table->foreignId('generated_by_agent_task_run_id')
                ->nullable()->constrained('agent_task_runs')->nullOnDelete();

            // Optional scoping (nullable; copied from the task when present)
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // Idempotency: one report per (repo, branch, day-window)
            $table->unique(['repo', 'branch', 'period_start', 'period_end'], 'commit_reports_unique_window');
            // UI query path: latest reports for a repo/branch by date
            $table->index(['repo', 'branch', 'period_start'], 'commit_reports_repo_branch_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commit_reports');
    }
};
