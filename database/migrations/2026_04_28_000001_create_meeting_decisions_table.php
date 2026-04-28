<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->json('participants')->nullable();
            $table->date('meeting_date');
            $table->timestamps();

            $table->index(['team_id', 'meeting_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_decisions');
    }
};
