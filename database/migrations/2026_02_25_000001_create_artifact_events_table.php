<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artifact_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained()->cascadeOnDelete();
            $table->string('event_id', 26)->unique(); // ULID
            $table->string('type'); // artifact.create | artifact.delete | layout.set
            $table->jsonb('payload');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['chat_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifact_events');
    }
};
