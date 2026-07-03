<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organization "second brain" instance — desired state + secrets for the
 * dedicated orchestrator (brain:reconcile) to run one Claude Code container per
 * enabled org.
 *
 * Sanctum stores only a hash of the MCP token, but the container needs the
 * plaintext bearer; and each org carries its own Claude credential. Both are
 * persisted encrypted at rest (Laravel `encrypted` cast under APP_KEY) and
 * hidden from serialization on the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('second_brain_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('service_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();

            // Secrets (encrypted cast on the model).
            $table->text('token_ciphertext')->nullable();          // plaintext TRIBESMCP_TOKEN bearer
            $table->string('claude_auth_type')->nullable();        // oauth | api_key
            $table->text('claude_auth_ciphertext')->nullable();    // per-org Claude credential

            // Desired + observed state.
            $table->boolean('enabled')->default(false)->index();
            $table->string('status')->default('disabled')->index(); // disabled|pending|running|stopping|stopped|error

            // Docker bookkeeping.
            $table->string('container_name')->nullable();
            $table->string('volume_name')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            // Bumped when the org's Claude credential changes, so a running
            // container is recreated on the next reconcile (credentials_changed_at
            // > last_started_at) without looping on ordinary status write-backs.
            $table->timestamp('credentials_changed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('second_brain_instances');
    }
};
