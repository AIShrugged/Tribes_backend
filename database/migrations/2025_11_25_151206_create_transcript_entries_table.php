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
        Schema::create('transcript_entries', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('calendar_event_id');
            $table->bigInteger('participant_id');
            $table->text('text');
            $table->double('start_relative');
            $table->double('end_relative');
            $table->timestampTz('start_absolute');
            $table->timestampTz('end_absolute');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transcript_entries');
    }
};
