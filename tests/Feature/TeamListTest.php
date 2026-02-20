<?php

namespace Tests\Feature;

use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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

        $this->organization->users()->attach($this->manager, ['role' => 'manager']);
        $this->organization->users()->attach($this->employee, ['role' => 'employee']);
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

    /** @test */
    public function manager_sees_all_teams_in_organization()
    {
        $teamA = $this->createTeam('Team A', 'team-a');
        $teamB = $this->createTeam('Team B', 'team-b');

        Sanctum::actingAs($this->manager);

        $response = $this->getJson("/api/v1/organizations/{$this->organization->id}/teams");

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($teamA->id, $ids);
        $this->assertContains($teamB->id, $ids);
    }

    /** @test */
    public function employee_sees_only_teams_they_belong_to()
    {
        $teamA = $this->createTeam('Team A', 'team-a');
        $this->createTeam('Team B', 'team-b');

        $teamA->users()->attach($this->employee);

        Sanctum::actingAs($this->employee);

        $response = $this->getJson("/api/v1/organizations/{$this->organization->id}/teams");

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonCount(1, 'data');

        $this->assertEquals($teamA->id, $response->json('data.0.id'));
    }

    /** @test */
    public function employee_not_in_any_team_sees_empty_list()
    {
        $this->createTeam('Team A', 'team-a');
        $this->createTeam('Team B', 'team-b');

        Sanctum::actingAs($this->employee);

        $response = $this->getJson("/api/v1/organizations/{$this->organization->id}/teams");

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonCount(0, 'data');
    }

    /** @test */
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

    /** @test */
    public function unauthenticated_user_gets_401()
    {
        $response = $this->getJson("/api/v1/organizations/{$this->organization->id}/teams");

        $response->assertUnauthorized();
    }

    /** @test */
    public function user_not_in_organization_gets_403()
    {
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);

        $response = $this->getJson("/api/v1/organizations/{$this->organization->id}/teams");

        $response->assertNotFound();
    }

    /** @test */
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
