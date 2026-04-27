<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_blockers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blocker_id')->constrained('issues')->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('issues')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['blocker_id', 'blocked_id']);
            $table->index('blocked_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_blockers');
    }
};
