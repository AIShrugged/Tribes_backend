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
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('source_id');
            $table->string('external_id')->nullable();
            $table->string('platform');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('url');
            $table->string('title');
            $table->text('description');
            $table->boolean('has_bot');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
