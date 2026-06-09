<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_task_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->integer('analyzed_count')->default(0);
            $table->string('status')->default('pending'); // pending, done, failed
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('organization_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_task_reviews');
    }
};
