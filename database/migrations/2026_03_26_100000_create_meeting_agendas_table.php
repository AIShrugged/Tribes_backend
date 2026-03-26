<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_agendas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type'); // general, personal
            $table->string('status')->default('pending'); // pending, in_progress, done, failed
            $table->text('content')->nullable();
            $table->json('raw_json')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('send_scheduled_at')->nullable();
            $table->timestamps();

            $table->unique(['calendar_event_id', 'user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_agendas');
    }
};
