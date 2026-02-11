<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_chat_id')->index();
            $table->foreignId('telegram_user_id')->nullable()->constrained('telegram_users')->nullOnDelete();
            $table->string('role', 20);
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_chat_messages');
    }
};
