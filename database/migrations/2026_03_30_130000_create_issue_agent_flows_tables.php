<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_agent_flows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('issue_id')->unique()->constrained('issues')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_profile_id')->nullable()->constrained('agent_profiles')->nullOnDelete();
            $table->string('status', 24)->default('planning');
            $table->unsignedSmallInteger('current_step_position')->nullable();
            $table->longText('plan_output')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['issue_id', 'status']);
            $table->index(['organization_id', 'team_id', 'status']);
        });

        Schema::create('issue_agent_flow_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('issue_agent_flow_id')->constrained('issue_agent_flows')->cascadeOnDelete();
            $table->foreignId('agent_task_id')->nullable()->constrained('agent_tasks')->nullOnDelete();
            $table->unsignedBigInteger('depends_on_step_id')->nullable();
            $table->unsignedSmallInteger('position');
            $table->string('kind', 24);
            $table->string('title');
            $table->text('prompt');
            $table->json('definition')->nullable();
            $table->json('input_payload')->nullable();
            $table->string('status', 24)->default('pending');
            $table->longText('output')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['issue_agent_flow_id', 'position']);
            $table->index(['issue_agent_flow_id', 'status']);
            $table->index(['agent_task_id']);
            $table->index(['depends_on_step_id']);
        });

        Schema::table('issues', function (Blueprint $table): void {
            if (! Schema::hasColumn('issues', 'issue_agent_flow_id')) {
                $table->foreignId('issue_agent_flow_id')->nullable()->after('agent_task_id')->constrained('issue_agent_flows')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            if (Schema::hasColumn('issues', 'issue_agent_flow_id')) {
                $table->dropConstrainedForeignId('issue_agent_flow_id');
            }
        });

        Schema::dropIfExists('issue_agent_flow_steps');
        Schema::dropIfExists('issue_agent_flows');
    }
};
