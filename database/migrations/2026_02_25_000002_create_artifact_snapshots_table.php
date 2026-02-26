<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artifact_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained()->cascadeOnDelete();
            $table->string('last_event_id', 26)->index(); // ULID of last included event
            $table->jsonb('state_json'); // full artifact state at snapshot moment
            $table->timestamp('created_at')->useCurrent();

            $table->index('chat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifact_snapshots');
    }
};
