<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('metadata');
        });

        Schema::create('agent_profile_prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_profile_id')->constrained('agent_profiles')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('system_prompt')->nullable();
            $table->timestamp('created_at', 0)->nullable();
            $table->unique(['agent_profile_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_profile_prompt_versions');

        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
