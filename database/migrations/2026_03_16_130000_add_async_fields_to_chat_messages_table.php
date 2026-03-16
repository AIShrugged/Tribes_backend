<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('status', 20)->default('completed')->after('role');
            $table->text('error_message')->nullable()->after('followup_data');
            $table->uuid('agent_run_uuid')->nullable()->index()->after('error_message');
            $table->timestamp('completed_at')->nullable()->after('agent_run_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['status', 'error_message', 'agent_run_uuid', 'completed_at']);
        });
    }
};
