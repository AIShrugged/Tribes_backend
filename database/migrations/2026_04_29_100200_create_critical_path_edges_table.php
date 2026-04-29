<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('critical_path_edges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('graph_id')->constrained('critical_path_graphs')->cascadeOnDelete();
            $table->foreignId('from_node_id')->constrained('critical_path_nodes')->cascadeOnDelete();
            $table->foreignId('to_node_id')->constrained('critical_path_nodes')->cascadeOnDelete();
            $table->enum('edge_type', ['explicit', 'implicit', 'sentinel'])->default('explicit');
            $table->timestamps();

            $table->unique(['graph_id', 'from_node_id', 'to_node_id']);
            $table->index('from_node_id');
            $table->index('to_node_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('critical_path_edges');
    }
};
