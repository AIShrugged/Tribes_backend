<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agenda_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('sections');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda_templates');
    }
};
