<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_command_audit', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('command_type');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('snapshot')->nullable();
            $table->json('inverse_payload')->nullable();   // reverse op for undo; TTL-purged
            $table->boolean('tainted')->default(false);
            $table->string('status')->default('committed'); // committed | failed
            $table->text('summary')->nullable();
            $table->timestamps();

            $table->index(['actor_id', 'created_at']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_command_audit');
    }
};
