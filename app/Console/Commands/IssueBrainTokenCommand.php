<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SecondBrainInstance;
use App\Services\SecondBrain\SecondBrainProvisioningService;
use Illuminate\Console\Command;

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
        {--name=second-brain : Token + service-user label}';

    protected $description = 'Provision/rotate the second-brain service user and its scoped MCP token for an organization';

    public function handle(SecondBrainProvisioningService $provisioning): int
    {
        $organization = Organization::find((int) $this->argument('organization'));

        if (! $organization) {
            $this->error("Organization #{$this->argument('organization')} not found.");

            return self::FAILURE;
        }

        $name = (string) $this->option('name');

        // The API-driven path (second_brain_instances) is authoritative. Rotating
        // the token here desyncs the stored ciphertext used to launch the container.
        if (SecondBrainInstance::where('organization_id', $organization->id)->exists()) {
            $this->warn('This organization has a managed second-brain instance; rotating the token manually will'
                .' desync the stored token and break its running container until you re-enable via the API.');
        }

        $token = $provisioning->issueToken($organization, $name);
        $serviceUser = $token->accessToken->tokenable;

        $this->info('Second-brain token provisioned.');
        $this->newLine();
        $this->line("  Organization : #{$organization->id} {$organization->name}");
        $this->line("  Service user : #{$serviceUser->id} {$serviceUser->email}");
        $this->line('  Ability      : mcp');
        $this->newLine();
        $this->line('  TRIBESMCP_TOKEN (store securely, shown once):');
        $this->line('  '.$token->plainTextToken);

        return self::SUCCESS;
    }
}
