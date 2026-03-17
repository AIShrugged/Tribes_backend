<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('prompt');
            $table->string('schedule_type', 20);
            $table->string('execution_mode', 20)->nullable();
            $table->string('sandbox_profile', 64)->nullable();
            $table->unsignedInteger('interval_seconds')->nullable();
            $table->string('agent_task_type', 32)->default('background');
            $table->string('output_mode', 16)->default('plain');
            $table->json('allowed_tools')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_completed_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->timestamp('locked_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'next_run_at']);
        });

        Schema::create('agent_task_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_task_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('run_token_hash', 255)->nullable();
            $table->timestamp('run_token_expires_at')->nullable();
            $table->longText('output')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['agent_task_id', 'created_at']);
            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_task_runs');
        Schema::dropIfExists('agent_tasks');
    }
};
