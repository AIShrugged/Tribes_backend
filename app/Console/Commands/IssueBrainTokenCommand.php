<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Provisions (or rotates) the per-organization service user + Sanctum token used
 * by the "second brain" sidecar to call the MCP server. The service user is an
 * organization MANAGER of exactly one organization, so every MCP tool — which
 * scopes by the authenticated user — automatically reads/writes only that org.
 *
 * The token is granted the single `mcp` ability, matching the `abilities:mcp`
 * guard on the /mcp route (least privilege).
 */
class IssueBrainTokenCommand extends Command
{
    protected $signature = 'brain:issue-token
        {organization : Organization id the second brain should operate on}
        {--name=second-brain : Token + service-user label}
        {--email= : Override the service-user email (defaults to second-brain+org<ID>@brain.tribesmcp.local)}';

    protected $description = 'Provision/rotate the second-brain service user and its scoped MCP token for an organization';

    public function handle(): int
    {
        $organization = Organization::find((int) $this->argument('organization'));

        if (! $organization) {
            $this->error("Organization #{$this->argument('organization')} not found.");

            return self::FAILURE;
        }

        $name = (string) $this->option('name');
        $email = (string) ($this->option('email')
            ?: "second-brain+org{$organization->id}@brain.tribesmcp.local");

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => "{$name} ({$organization->name})",
                'password' => Hash::make(Str::random(48)),
                'email_verified_at' => now(),
            ],
        );

        // Manager of exactly this org → org-wide visibility, scoped to one tenant.
        $organization->users()->syncWithoutDetaching([
            $user->id => ['role' => UserRole::MANAGER->value],
        ]);

        // Rotate: drop any previous brain tokens with the same label.
        $user->tokens()->where('name', $name)->delete();

        $token = $user->createToken($name, ['mcp']);

        $this->info('Second-brain token provisioned.');
        $this->newLine();
        $this->line("  Organization : #{$organization->id} {$organization->name}");
        $this->line("  Service user : #{$user->id} {$email}");
        $this->line('  Ability      : mcp');
        $this->newLine();
        $this->line('  TRIBESMCP_TOKEN (store securely, shown once):');
        $this->line('  '.$token->plainTextToken);

        return self::SUCCESS;
    }
}
