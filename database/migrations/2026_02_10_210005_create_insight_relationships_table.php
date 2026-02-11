<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insight_relationships', function (Blueprint $table) {
            $table->id();
            $table->string('email_a');
            $table->string('email_b');
            $table->jsonb('dynamics')->default('{}');
            $table->string('relationship_type')->default('neutral');
            $table->unsignedInteger('interaction_count')->default(1);
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamps();

            $table->unique(['email_a', 'email_b']);
            $table->index('email_a');
            $table->index('email_b');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_relationships');
    }
};
