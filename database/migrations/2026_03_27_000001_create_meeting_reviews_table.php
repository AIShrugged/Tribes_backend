<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('in_progress');
            $table->decimal('score', 4, 2)->nullable();
            $table->json('score_breakdown')->nullable();
            $table->text('key_insight')->nullable();
            $table->json('suggestions')->nullable();
            $table->json('agenda_analysis')->nullable();
            $table->json('participation')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_reviews');
    }
};
