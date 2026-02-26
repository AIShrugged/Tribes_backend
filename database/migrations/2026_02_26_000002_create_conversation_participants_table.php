<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('participantable_type', 255);
            $table->unsignedBigInteger('participantable_id');
            $table->string('role', 20)->default('member'); // owner | member
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamps();

            $table->unique(
                ['conversation_id', 'participantable_type', 'participantable_id'],
                'unique_conversation_participant'
            );
            $table->index(['participantable_type', 'participantable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_participants');
    }
};
