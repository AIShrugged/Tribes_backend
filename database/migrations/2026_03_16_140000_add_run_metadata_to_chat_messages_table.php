<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->unsignedSmallInteger('current_attempt')->default(0)->after('agent_run_uuid');
            $table->unsignedSmallInteger('max_attempts')->default(1)->after('current_attempt');
            $table->timestamp('next_retry_at')->nullable()->after('completed_at');
            $table->string('failure_code', 120)->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['current_attempt', 'max_attempts', 'next_retry_at', 'failure_code']);
        });
    }
};
