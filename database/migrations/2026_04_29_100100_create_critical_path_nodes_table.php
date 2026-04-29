<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('critical_path_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('graph_id')->constrained('critical_path_graphs')->cascadeOnDelete();
            $table->foreignId('issue_id')->nullable()->constrained('issues')->cascadeOnDelete();
            $table->enum('node_type', ['issue', 'start', 'end'])->default('issue');
            $table->decimal('duration_days', 8, 2)->default(1.0);
            $table->decimal('early_start', 10, 2)->nullable();
            $table->decimal('early_finish', 10, 2)->nullable();
            $table->decimal('late_start', 10, 2)->nullable();
            $table->decimal('late_finish', 10, 2)->nullable();
            $table->decimal('slack', 10, 2)->nullable();
            $table->boolean('is_critical')->default(false);
            $table->timestamps();

            $table->unique(['graph_id', 'issue_id']);
            $table->index(['graph_id', 'is_critical']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('critical_path_nodes');
    }
};
