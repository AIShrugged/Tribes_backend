<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->uuid('agent_run_uuid')->nullable();
            $table->string('tool_name');
            $table->string('description');
            $table->boolean('success');
            $table->json('tool_args')->nullable();
            $table->json('tool_result')->nullable();
            $table->timestamp('created_at');

            $table->index('user_id');
            $table->index('chat_id');
            $table->index('agent_run_uuid');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_activity_logs');
    }
};
