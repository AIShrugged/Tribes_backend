<?php

namespace App\Observers;

use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Services\LlmPromptProvisioningService;
use App\Services\Organization\ProjectCodeGenerator;
use App\Services\Workspace\WorkspaceBootstrapService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrganizationObserver
{
    public function __construct(
        private readonly ProjectCodeGenerator $codeGenerator,
    ) {}

    /**
     * Assign the Jira-like project code before insert. An explicitly-supplied code
     * (validated in OrganizationRequest) is kept as-is (uppercased); otherwise one is
     * generated from the name. The code is immutable, so this only runs on creation.
     */
    public function creating(Organization $organization): void
    {
        if (blank($organization->code)) {
            $organization->code = $this->codeGenerator->generate((string) $organization->name);

            return;
        }

        $organization->code = strtoupper($organization->code);
    }

    public function created(Organization $organization): void
    {
        // Org-shared workspace is methodology-independent and must always exist —
        // failure here is a hard failure for org creation (no workspace = broken).
        // Runs BEFORE ensureDefaultTeam so the team-level workspace, which
        // implicitly depends on the org-shared one, can be created after.
        app(WorkspaceBootstrapService::class)->ensureOrganizationDefaults($organization);

        // Default team MUST be provisioned before any code path that resolves
        // membership or team-scoped features. Failures bubble — caller's
        // surrounding transaction rolls back the org creation, which is the
        // correct semantic (an org without a default team is a broken invariant).
        // Exception: when default methodology is absent, we skip with a warning
        // (degraded mode) since the team can't be assigned a methodology.
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
     * Create one is_default=true team per org and bootstrap its workspace.
     * Owns team-level workspace bootstrap so OrganizationMembershipService::add()
     * doesn't need to call it on every attach (O(N) for O(1) work).
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

            app(WorkspaceBootstrapService::class)->ensureTeamDefaults($team);

            return $team;
        });
    }
}
