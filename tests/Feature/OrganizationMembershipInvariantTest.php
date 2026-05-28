<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\OrganizationMembershipService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pins the "org member ⇒ default-team member" invariant.
 *
 * Every direct OrganizationMembershipService::add (and observer's auto-default
 * team creation) is exercised here. If any of these tests fail, the invariant
 * is broken — most likely either the observer regressed or a new code path
 * attaches to organization_user directly bypassing the service.
 *
 * See plan docs/plans/2026-05-28-feat-default-team-per-organization-plan.md
 * (Phase 5a) for the wider rationale.
 */
class OrganizationMembershipInvariantTest extends TestCase
{
    use RefreshDatabase;

    private function ensureDefaultMethodology(): Methodology
    {
        return Methodology::where('is_default', true)->first()
            ?? Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ]);
    }

    #[Test]
    public function creating_organization_creates_default_team(): void
    {
        $this->ensureDefaultMethodology();

        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);

        $this->assertNotNull($org->refresh()->defaultTeam);
        $this->assertTrue($org->defaultTeam->isDefault());
        $this->assertSame('General', $org->defaultTeam->name);
    }

    #[Test]
    public function default_team_uses_default_methodology(): void
    {
        $methodology = $this->ensureDefaultMethodology();

        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);

        $this->assertSame($methodology->id, $org->refresh()->defaultTeam->methodology_id);
    }

    #[Test]
    public function default_team_unique_per_organization(): void
    {
        $this->ensureDefaultMethodology();

        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);

        $this->expectException(QueryException::class);
        Team::create([
            'organization_id' => $org->id,
            'methodology_id' => $this->ensureDefaultMethodology()->id,
            'name' => 'Second Default',
            'slug' => 'second-default',
            'is_default' => true,
        ]);
    }

    #[Test]
    public function adding_user_via_service_attaches_to_default_team(): void
    {
        $this->ensureDefaultMethodology();
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();

        app(OrganizationMembershipService::class)->add($org, $user, UserRole::MANAGER);

        $this->assertTrue($org->refresh()->users()->where('users.id', $user->id)->exists());
        $this->assertTrue($org->defaultTeam->users()->where('users.id', $user->id)->exists());
    }

    #[Test]
    public function service_add_is_idempotent_on_pivot(): void
    {
        $this->ensureDefaultMethodology();
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();

        $svc = app(OrganizationMembershipService::class);
        $svc->add($org, $user, UserRole::EMPLOYEE);
        $svc->add($org, $user, UserRole::EMPLOYEE);

        $this->assertSame(1, $org->users()->where('users.id', $user->id)->count());
        $this->assertSame(1, $org->refresh()->defaultTeam->users()->where('users.id', $user->id)->count());
    }

    #[Test]
    public function service_remove_detaches_from_org_and_default_team(): void
    {
        $this->ensureDefaultMethodology();
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $svc = app(OrganizationMembershipService::class);
        $svc->add($org, $user, UserRole::EMPLOYEE);

        $svc->remove($org, $user);

        $this->assertFalse($org->users()->where('users.id', $user->id)->exists());
        $this->assertFalse($org->refresh()->defaultTeam->users()->where('users.id', $user->id)->exists());
    }

    #[Test]
    public function service_remove_keeps_user_in_real_teams(): void
    {
        $methodology = $this->ensureDefaultMethodology();
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $svc = app(OrganizationMembershipService::class);
        $svc->add($org, $user, UserRole::EMPLOYEE);

        // Create a real team and attach the user explicitly.
        $realTeam = Team::create([
            'organization_id' => $org->id,
            'methodology_id' => $methodology->id,
            'name' => 'Backenders',
            'slug' => 'backenders',
        ]);
        $realTeam->users()->attach($user->id);

        $svc->remove($org, $user);

        // User is gone from org + default team, but still in the real team.
        $this->assertFalse($org->users()->where('users.id', $user->id)->exists());
        $this->assertFalse($org->refresh()->defaultTeam->users()->where('users.id', $user->id)->exists());
        $this->assertTrue($realTeam->users()->where('users.id', $user->id)->exists());
    }

    #[Test]
    public function add_many_attaches_all_members_to_default_team(): void
    {
        $this->ensureDefaultMethodology();
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $users = User::factory()->count(3)->create();

        $members = $users->map(fn (User $u) => ['user' => $u, 'role' => UserRole::EMPLOYEE])->all();
        app(OrganizationMembershipService::class)->addMany($org, $members);

        foreach ($users as $u) {
            $this->assertTrue($org->users()->where('users.id', $u->id)->exists(), "User {$u->id} missing from org");
            $this->assertTrue(
                $org->refresh()->defaultTeam->users()->where('users.id', $u->id)->exists(),
                "User {$u->id} missing from default team"
            );
        }
    }

    #[Test]
    public function organization_without_default_methodology_still_creates_but_logs_warning(): void
    {
        // Remove default methodology entirely. Observer should log warning,
        // NOT throw, so org creation succeeds in degraded mode.
        Methodology::where('is_default', true)->delete();

        $org = Organization::create(['name' => 'No Methodology', 'slug' => 'no-methodology']);

        $this->assertNotNull($org->id);
        $this->assertNull($org->refresh()->defaultTeam);
    }
}
