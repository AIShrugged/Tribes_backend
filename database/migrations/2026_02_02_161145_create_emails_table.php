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
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->string('from');
            $table->string('from_name')->nullable();
            $table->json('to');
            $table->string('subject');
            $table->text('html_body');
            $table->json('attachments')->nullable();
            $table->enum('status', ['pending', 'sent', 'failed', 'queued'])->default('pending');
            $table->string('provider')->default('unisender_go');
            $table->string('provider_message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
