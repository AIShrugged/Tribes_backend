<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_tasks', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();

            $table->index(['organization_id', 'team_id', 'enabled', 'next_run_at'], 'agent_tasks_scope_schedule_index');
        });
    }

    public function down(): void
    {
        Schema::table('agent_tasks', function (Blueprint $table) {
            $table->dropIndex('agent_tasks_scope_schedule_index');
            $table->dropConstrainedForeignId('team_id');
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
