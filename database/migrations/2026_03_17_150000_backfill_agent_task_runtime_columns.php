<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_tasks')) {
            Schema::table('agent_tasks', function (Blueprint $table) {
                if (! Schema::hasColumn('agent_tasks', 'execution_mode')) {
                    $table->string('execution_mode', 20)->nullable()->after('schedule_type');
                }

                if (! Schema::hasColumn('agent_tasks', 'sandbox_profile')) {
                    $table->string('sandbox_profile', 64)->nullable()->after('execution_mode');
                }

                if (! Schema::hasColumn('agent_tasks', 'interval_seconds')) {
                    $table->unsignedInteger('interval_seconds')->nullable()->after('sandbox_profile');
                }

                if (! Schema::hasColumn('agent_tasks', 'agent_task_type')) {
                    $table->string('agent_task_type', 32)->default('background')->after('interval_seconds');
                }

                if (! Schema::hasColumn('agent_tasks', 'output_mode')) {
                    $table->string('output_mode', 16)->default('plain')->after('agent_task_type');
                }

                if (! Schema::hasColumn('agent_tasks', 'allowed_tools')) {
                    $table->json('allowed_tools')->nullable()->after('output_mode');
                }

                if (! Schema::hasColumn('agent_tasks', 'allowed_outbound_hosts')) {
                    $table->json('allowed_outbound_hosts')->nullable()->after('allowed_tools');
                }

                if (! Schema::hasColumn('agent_tasks', 'input_payload')) {
                    $table->json('input_payload')->nullable()->after('allowed_outbound_hosts');
                }

                if (! Schema::hasColumn('agent_tasks', 'next_run_at')) {
                    $table->timestamp('next_run_at')->nullable()->after('input_payload');
                }

                if (! Schema::hasColumn('agent_tasks', 'last_run_at')) {
                    $table->timestamp('last_run_at')->nullable()->after('next_run_at');
                }

                if (! Schema::hasColumn('agent_tasks', 'last_completed_at')) {
                    $table->timestamp('last_completed_at')->nullable()->after('last_run_at');
                }

                if (! Schema::hasColumn('agent_tasks', 'last_failed_at')) {
                    $table->timestamp('last_failed_at')->nullable()->after('last_completed_at');
                }

                if (! Schema::hasColumn('agent_tasks', 'last_error')) {
                    $table->text('last_error')->nullable()->after('last_failed_at');
                }

                if (! Schema::hasColumn('agent_tasks', 'enabled')) {
                    $table->boolean('enabled')->default(true)->after('last_error');
                }

                if (! Schema::hasColumn('agent_tasks', 'max_attempts')) {
                    $table->unsignedSmallInteger('max_attempts')->default(3)->after('enabled');
                }

                if (! Schema::hasColumn('agent_tasks', 'locked_at')) {
                    $table->timestamp('locked_at')->nullable()->after('max_attempts');
                }

                if (! Schema::hasColumn('agent_tasks', 'metadata')) {
                    $table->json('metadata')->nullable()->after('locked_at');
                }
            });
        }

        if (Schema::hasTable('agent_task_runs')) {
            Schema::table('agent_task_runs', function (Blueprint $table) {
                if (! Schema::hasColumn('agent_task_runs', 'run_token_hash')) {
                    $table->string('run_token_hash', 255)->nullable()->after('scheduled_for');
                }

                if (! Schema::hasColumn('agent_task_runs', 'run_token_expires_at')) {
                    $table->timestamp('run_token_expires_at')->nullable()->after('run_token_hash');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agent_task_runs')) {
            Schema::table('agent_task_runs', function (Blueprint $table) {
                $columns = [];

                foreach (['run_token_hash', 'run_token_expires_at'] as $column) {
                    if (Schema::hasColumn('agent_task_runs', $column)) {
                        $columns[] = $column;
                    }
                }

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('agent_tasks')) {
            Schema::table('agent_tasks', function (Blueprint $table) {
                $columns = [];

                foreach ([
                    'execution_mode',
                    'sandbox_profile',
                    'interval_seconds',
                    'agent_task_type',
                    'output_mode',
                    'allowed_tools',
                    'allowed_outbound_hosts',
                    'input_payload',
                    'next_run_at',
                    'last_run_at',
                    'last_completed_at',
                    'last_failed_at',
                    'last_error',
                    'enabled',
                    'max_attempts',
                    'locked_at',
                    'metadata',
                ] as $column) {
                    if (Schema::hasColumn('agent_tasks', $column)) {
                        $columns[] = $column;
                    }
                }

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
