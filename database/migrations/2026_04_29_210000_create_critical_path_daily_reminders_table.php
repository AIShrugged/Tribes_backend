<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('critical_path_daily_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained('issues')->cascadeOnDelete();
            $table->foreignId('graph_id')->nullable()->constrained('critical_path_graphs')->nullOnDelete();
            $table->date('date');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['user_id', 'issue_id', 'date']);
            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('critical_path_daily_reminders');
    }
};
