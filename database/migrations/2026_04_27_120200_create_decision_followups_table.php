<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('decision_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->string('status');
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('decision_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_followups');
    }
};
