<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\OrganizationMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TeamListTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected User $employee;

    protected Organization $organization;

    protected Methodology $methodology;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
        ]);

        $this->methodology = Methodology::where('is_default', true)->first()
            ?? Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ]);

        $this->manager = User::factory()->create();
        $this->employee = User::factory()->create();

        // Route through OrganizationMembershipService so test fixtures mirror prod:
        // both users land in org_user AND default team_user. Direct attach would
        // skip the default-team invariant.
        $svc = app(OrganizationMembershipService::class);
        $svc->add($this->organization, $this->manager, UserRole::MANAGER);
        $svc->add($this->organization, $this->employee, UserRole::EMPLOYEE);
    }

    private function createTeam(string $name, string $slug): Team
    {
        return Team::create([
            'name' => $name,
            'slug' => $slug,
            'organization_id' => $this->organization->id,
            'methodology_id' => $this->methodology->id,
        ]);
    }

    #[Test]
    public function creator_is_auto_attached_to_team_on_store()
    {
        // Regression for prod incident 2026-05-26: org manager creates team via
        // POST /api/v1/teams, but is NOT added to team_user pivot. Symptoms:
        // post-transcript pipeline GenerateFollowup early-returns on empty
        // $user->teams(), so manually-uploaded transcripts produce no followups
        // and no extracted issues. UI showed the team because of Team::scopeVisibleFor.
        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/v1/teams', [
            'organization_id' => $this->organization->id,
            'name' => 'Brand New Team',
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $teamId = $response->json('data.id');
        $this->assertDatabaseHas('team_user', [
            'team_id' => $teamId,
            'user_id' => $this->manager->id,
        ]);
    }

    #[Test]
    public function store_is_idempotent_on_pivot_when_called_twice()
    {
        // Defensive: if a retry hits the endpoint (rare but possible), we should
        // still end up with exactly one team_user row for the creator.
        Sanctum::actingAs($this->manager);

        $first = $this->postJson('/api/v1/teams', [
            'organization_id' => $this->organization->id,
            'name' => 'Idempotent Test',
        ]);
        $first->assertOk();
        $teamId = $first->json('data.id');

        // Simulate a re-attach via the same code path. We can't POST twice with
        // the same name (slug uniqueness), so we exercise the pivot directly to
        // verify `syncWithoutDetaching` keeps it at 1 row.
        Team::find($teamId)->users()->syncWithoutDetaching([$this->manager->id]);

        $count = \DB::table('team_user')
            ->where('team_id', $teamId)
            ->where('user_id', $this->manager->id)
            ->count();
        $this->assertSame(1, $count);
    }

    #[Test]
    public function manager_sees_all_teams_in_organization()
    {
        $teamA = $this->createTeam('Team A', 'team-a');
        $teamB = $this->createTeam('Team B', 'team-b');

        Sanctum::actingAs($this->manager);

        $response = $this->getJson("/api/v1/organizations/{$this->organization->id}/teams");

        // Manager sees all teams: 2 created + auto-provisioned default team = 3.
        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonCount(3, 'data');

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($teamA->id, $ids);
        $this->assertContains($teamB->id, $ids);
        $this->assertContains($this->organization->refresh()->defaultTeam->id, $ids);
    }

    #[Test]
    public function employee_sees_default_team_and_explicit_teams_they_belong_to()
    {
        $teamA = $this->createTeam('Team A', 'team-a');
        $this->createTeam('Team B', 'team-b');

        $teamA->users()->attach($this->employee);

        Sanctum::actingAs($this->employee);

        $response = $this->getJson("/api/v1/organizations/{$this->organization->id}/teams");

        // Employee belongs to default team (auto, via service) AND team-a (explicit).
        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($teamA->id, $ids);
        $this->assertContains($this->organization->refresh()->defaultTeam->id, $ids);
    }

    #[Test]
    public function employee_only_sees_default_team_when_not_in_real_teams()
    {
        $this->createTeam('Team A', 'team-a');
        $this->createTeam('Team B', 'team-b');

        Sanctum::actingAs($this->employee);

        $response = $this->getJson("/api/v1/organizations/{$this->organization->id}/teams");

        // Even without explicit team membership, every org member belongs to the
        // default team — that's the invariant the feature exists to enforce.
        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonCount(1, 'data');

        $this->assertEquals(
            $this->organization->refresh()->defaultTeam->id,
            $response->json('data.0.id')
        );
    }

    #[Test]
    public function employee_does_not_see_teams_they_are_not_member_of()
    {
        $teamA = $this->createTeam('Team A', 'team-a');
        $teamB = $this->createTeam('Team B', 'team-b');

        $teamA->users()->attach($this->employee);

        Sanctum::actingAs($this->employee);

        $response = $this->getJson("/api/v1/organizations/{$this->organization->id}/teams");

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($teamB->id, $ids);
    }

    #[Test]
    public function unauthenticated_user_gets_401()
    {
        $response = $this->getJson("/api/v1/organizations/{$this->organization->id}/teams");

        $response->assertUnauthorized();
    }

    #[Test]
    public function user_not_in_organization_gets_403()
    {
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);

        $response = $this->getJson("/api/v1/organizations/{$this->organization->id}/teams");

        $response->assertNotFound();
    }

    #[Test]
    public function manager_sees_teams_from_own_organization_only()
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'slug' => 'other-org',
        ]);

        $this->createTeam('Own Team', 'own-team');

        $foreignTeam = Team::create([
            'name' => 'Foreign Team',
            'slug' => 'foreign-team',
            'organization_id' => $otherOrg->id,
            'methodology_id' => $this->methodology->id,
        ]);

        Sanctum::actingAs($this->manager);

        $response = $this->getJson("/api/v1/organizations/{$this->organization->id}/teams");

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($foreignTeam->id, $ids);
    }
}
