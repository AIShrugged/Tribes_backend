<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_user_id')->constrained()->onDelete('cascade');
            $table->string('key');
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['telegram_user_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_memories');
    }
};
