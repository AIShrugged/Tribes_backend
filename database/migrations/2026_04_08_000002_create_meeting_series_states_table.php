<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_series_states', function (Blueprint $table) {
            $table->id();
            $table->string('series_identifier')->unique();
            $table->text('content')->nullable();
            $table->foreignId('source_event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_series_states');
    }
};
