<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_compaction_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('conversation_key');
            $table->unsignedInteger('keep_recent_messages');
            $table->unsignedInteger('message_count');
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->text('summary');
            $table->timestamps();
            $table->unique(['conversation_key', 'keep_recent_messages'], 'conversation_compaction_snapshots_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_compaction_snapshots');
    }
};
