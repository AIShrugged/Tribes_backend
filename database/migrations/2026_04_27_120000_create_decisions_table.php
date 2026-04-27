<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('summary_id')->nullable()->constrained('meeting_summaries')->nullOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('author_profile_id')->nullable()->constrained('profiles')->nullOnDelete();
            $table->string('author_raw_name')->nullable();
            $table->text('text');
            $table->string('topic')->nullable();
            $table->timestamps();

            $table->index('calendar_event_id');
            $table->index('author_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decisions');
    }
};
