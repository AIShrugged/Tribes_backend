<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_summary_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('meeting_summary_templates')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('sections');
            $table->text('prompt_override')->nullable();
            $table->timestamp('created_at', 0)->nullable();
            $table->unique(['template_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_summary_template_versions');
    }
};
