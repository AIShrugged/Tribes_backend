<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('system_prompt')->nullable();
            $table->json('config_schema')->nullable();
            $table->json('task_payload_schema')->nullable();
            $table->string('execution_mode', 20)->default('inline');
            $table->string('sandbox_profile', 64)->nullable();
            $table->json('allowed_tools')->nullable();
            $table->json('allowed_outbound_hosts')->nullable();
            $table->string('default_model', 120)->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_profile_id')->constrained('agent_profiles')->cascadeOnDelete();
            $table->string('scope_type', 32)->default('profile');
            $table->string('scope_key', 191)->nullable();
            $table->string('kind', 32)->default('instruction');
            $table->unsignedSmallInteger('priority')->default(50);
            $table->boolean('active')->default(true);
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['agent_profile_id', 'scope_type', 'scope_key']);
        });

        Schema::table('agent_tasks', function (Blueprint $table) {
            $table->foreignId('agent_profile_id')->nullable()->after('user_id')->constrained('agent_profiles')->nullOnDelete();
            $table->json('input_payload')->nullable()->after('prompt');
            $table->json('allowed_outbound_hosts')->nullable()->after('allowed_tools');
        });
    }

    public function down(): void
    {
        Schema::table('agent_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_profile_id');
            $table->dropColumn(['input_payload', 'allowed_outbound_hosts']);
        });

        Schema::dropIfExists('agent_memories');
        Schema::dropIfExists('agent_profiles');
    }
};
