<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upcoming_agendas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_calendar_event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending, in_progress, done, failed
            $table->text('content')->nullable();
            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->unique('user_id'); // one upcoming agenda per user, replaced on each new meeting
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upcoming_agendas');
    }
};
