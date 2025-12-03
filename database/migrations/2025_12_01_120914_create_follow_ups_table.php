<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('followups', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('calendar_event_id');
            $table->bigInteger('participant_id')->nullable();
            $table->enum('scope', ['shared', 'personal']);
            $table->string('type');
            $table->text('text');
            $table->enum('status', ['in_progress', 'done', 'failed']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('followups');
    }
};
