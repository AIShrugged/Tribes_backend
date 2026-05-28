<?php

namespace App\Observers;

use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Services\LlmPromptProvisioningService;
use App\Services\Workspace\WorkspaceBootstrapService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrganizationObserver
{
    public function created(Organization $organization): void
    {
        // Default team MUST be provisioned before any code path that resolves
        // membership or team-scoped features. Failures bubble — caller's
        // surrounding transaction rolls back the org creation, which is the
        // correct semantic (an org without a default team is a broken invariant).
        $this->ensureDefaultTeam($organization);

        // LLM prompts are best-effort: prompt provisioning failing should not
        // kill organization creation. Logged for follow-up.
        try {
            app(LlmPromptProvisioningService::class)->provisionForOrganization($organization);
        } catch (\Throwable $e) {
            Log::warning('Failed to provision default LLM prompts for organization', [
                'organization_id' => $organization->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create one is_default=true team per org. Owns org-level workspace
     * bootstrap so OrganizationMembershipService::add() doesn't need to call
     * ensureOrganizationDefaults on every attach (O(N) for O(1) work).
     */
    private function ensureDefaultTeam(Organization $organization): ?Team
    {
        $methodology = Methodology::query()->where('is_default', true)->first();
        if (! $methodology) {
            Log::warning('Default methodology missing — skipping default team creation', [
                'organization_id' => $organization->id,
            ]);

            return null;
        }

        return DB::transaction(function () use ($organization, $methodology): Team {
            $team = Team::firstOrCreate(
                ['organization_id' => $organization->id, 'is_default' => true],
                [
                    'name' => 'General',
                    'slug' => 'general',
                    'methodology_id' => $methodology->id,
                ]
            );

            $workspace = app(WorkspaceBootstrapService::class);
            $workspace->ensureOrganizationDefaults($organization);
            $workspace->ensureTeamDefaults($team);

            return $team;
        });
    }
}
