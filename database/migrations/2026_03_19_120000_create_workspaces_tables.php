<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('scope_type', 32);
            $table->string('root_prefix')->unique();
            $table->string('storage_disk')->default('s3');
            $table->string('status', 32)->default('active');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'scope_type']);
            $table->index(['team_id', 'scope_type']);
            $table->index(['owner_user_id', 'scope_type']);
        });

        Schema::create('workspace_permissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('workspace_id');
            $table->string('principal_type', 32);
            $table->string('principal_id', 64);
            $table->boolean('can_list')->default(false);
            $table->boolean('can_read')->default(false);
            $table->boolean('can_write')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->boolean('can_execute')->default(false);
            $table->boolean('can_admin')->default(false);
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['workspace_id', 'principal_type', 'principal_id'], 'workspace_permissions_unique_principal');
            $table->index(['principal_type', 'principal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_permissions');
        Schema::dropIfExists('workspaces');
    }
};
