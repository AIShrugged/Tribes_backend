<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            if (! Schema::hasColumn('issues', 'last_agent_execution_mode')) {
                $table->string('last_agent_execution_mode', 20)
                    ->nullable()
                    ->after('agent_task_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            if (Schema::hasColumn('issues', 'last_agent_execution_mode')) {
                $table->dropColumn('last_agent_execution_mode');
            }
        });
    }
};
