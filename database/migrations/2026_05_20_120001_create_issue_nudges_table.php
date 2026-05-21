<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_nudges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained('issues')->cascadeOnDelete();
            $table->string('kind'); // 'executor' | 'manager'
            $table->foreignId('recipient_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->unsignedSmallInteger('attempt_no'); // executor: 1|2; manager: 1
            $table->string('template_key'); // 'exec_1' | 'exec_2' | 'escalation'
            $table->unsignedSmallInteger('days_stuck');
            $table->timestamp('sent_at')->nullable();
            $table->string('status'); // 'sent' | 'failed' | 'skipped'
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            // Hot-path: series fetch (WHERE issue_id=? AND created_at > ?)
            $table->index(['issue_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_nudges');
    }
};
