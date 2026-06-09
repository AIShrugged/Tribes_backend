<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_task_review_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_task_review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->string('progress'); // done, in_progress, blocked
            $table->string('confidence'); // high, medium, low
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('meeting_task_review_id');
            $table->index('issue_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_task_review_items');
    }
};
