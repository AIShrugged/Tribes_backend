<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activity / reasoning log for the external "second brain" agent. Each loop
 * cycle posts its full Claude transcript (reasoning, tool calls, tool results,
 * final summary) here, normalized into one row per event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brain_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Correlates all events produced within one loop cycle (UUID or any token).
            $table->string('run_uuid', 64)->nullable();
            // Order of the event within its cycle.
            $table->unsignedInteger('seq')->default(0);
            // reasoning | thinking | tool_call | tool_result | cycle_summary | cycle_start | note
            $table->string('type')->index();
            // For tool_call/tool_result events.
            $table->string('tool_name')->nullable();
            // Human-readable text: the brain's reasoning, written output, or result.
            $table->longText('content')->nullable();
            // Raw structured detail (tool args/result, cost/turns/session metadata, etc.).
            $table->jsonb('payload')->nullable();
            // When the event actually occurred on the agent side (vs server receipt).
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'run_uuid', 'seq']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brain_events');
    }
};
