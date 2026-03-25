<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('deduplication_key');
        });

        Schema::create('bot_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bot_id');
            $table->string('type'); // scheduled, joined, removed, kicked, transcript_done
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('bot_id')
                ->references('calendar_event_id')
                ->on('bots')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_events');

        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
