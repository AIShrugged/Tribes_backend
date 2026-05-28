<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\Workspace\WorkspaceBootstrapService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single API for adding/removing users to/from organizations.
 *
 * MUST be the only code path that writes to organization_user. Direct calls
 * to $org->users()->attach() / syncWithoutDetaching() elsewhere in app/ will
 * skip default-team attach and silently break team-scoped pipeline features.
 *
 * Enforcement: DirectOrgAttachIsForbiddenTest greps app/ for offenders. Add
 * `// @membership-allow direct attach` to whitelist legitimate exceptions
 * (e.g., test-only seeders) when needed.
 *
 * Workspace bootstrap split:
 *   - Org-level (ensureOrganizationDefaults) lives in OrganizationObserver,
 *     runs once per org. NOT called here — that would be O(N) for O(1) work.
 *   - User-personal (ensureUserPersonalSharedWorkspace) runs here, once per
 *     member attach.
 *   - user_team_private workspace intentionally skipped for default team —
 *     "private workspace in the all-org team" has no clear semantics.
 *
 * See plan docs/plans/2026-05-28-feat-default-team-per-organization-plan.md
 * for full rationale.
 */
class OrganizationMembershipService
{
    public function __construct(
        private readonly WorkspaceBootstrapService $workspace,
    ) {}

    /**
     * Attach a single user to an organization and its default team. Idempotent.
     */
    public function add(Organization $organization, User $user, UserRole $role): void
    {
        DB::transaction(function () use ($organization, $user, $role): void {
            $organization->users()->syncWithoutDetaching([
                $user->id => ['role' => $role->value],
            ]);

            $defaultTeam = $organization->defaultTeam;
            if ($defaultTeam !== null) {
                $defaultTeam->users()->syncWithoutDetaching([$user->id]);
                $this->workspace->ensureUserPersonalSharedWorkspace($user, $defaultTeam);
            } else {
                Log::warning('Organization has no default team; attaching to org only', [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                ]);
            }
        });
    }

    /**
     * Bulk-attach many users in a single transaction. Use from onboarding
     * flows where N users land in the org at once — avoids N individual
     * transactions and N×workspace-bootstrap rounds.
     *
     * @param  array<int, array{user: User, role: UserRole}>  $members
     */
    public function addMany(Organization $organization, array $members): void
    {
        if ($members === []) {
            return;
        }

        DB::transaction(function () use ($organization, $members): void {
            $orgPivot = [];
            $teamPivot = [];
            foreach ($members as $member) {
                $orgPivot[$member['user']->id] = ['role' => $member['role']->value];
                $teamPivot[] = $member['user']->id;
            }

            $organization->users()->syncWithoutDetaching($orgPivot);

            $defaultTeam = $organization->defaultTeam;
            if ($defaultTeam !== null) {
                $defaultTeam->users()->syncWithoutDetaching($teamPivot);
                foreach ($members as $member) {
                    $this->workspace->ensureUserPersonalSharedWorkspace($member['user'], $defaultTeam);
                }
            } else {
                Log::warning('Organization has no default team; bulk-attaching to org only', [
                    'organization_id' => $organization->id,
                    'user_count' => count($members),
                ]);
            }
        });
    }

    /**
     * Detach user from the organization and its default team. Real (non-default)
     * team memberships are intentionally NOT touched — those represent explicit
     * assignments and require an explicit decision to unwind.
     */
    public function remove(Organization $organization, User $user): void
    {
        DB::transaction(function () use ($organization, $user): void {
            $organization->users()->detach($user->id);

            $defaultTeam = $organization->defaultTeam;
            if ($defaultTeam !== null) {
                $defaultTeam->users()->detach($user->id);
            }
        });
    }
}
