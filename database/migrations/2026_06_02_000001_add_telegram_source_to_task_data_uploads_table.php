<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_data_uploads', function (Blueprint $table) {
            $table->bigInteger('source_telegram_chat_id')->nullable()->after('original_filename');
            $table->bigInteger('source_telegram_thread_id')->nullable()->after('source_telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('task_data_uploads', function (Blueprint $table) {
            $table->dropColumn(['source_telegram_chat_id', 'source_telegram_thread_id']);
        });
    }
};
