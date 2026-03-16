<?php

namespace Tests\Feature;

use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MethodologyListTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;
    protected User $employee;
    protected Organization $organization;
    protected Methodology $defaultMethodology;
    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
        ]);

        $this->defaultMethodology = Methodology::where('is_default', true)->first()
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

        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'organization_id' => $this->organization->id,
            'methodology_id' => $this->defaultMethodology->id,
        ]);
    }

    private function createMethodology(string $name, ?Team $team = null): Methodology
    {
        $methodology = Methodology::create([
            'name' => $name,
            'text' => 'Some text',
            'scheme' => '{}',
            'is_default' => false,
            'organization_id' => $this->organization->id,
        ]);

        if ($team) {
            $team->update(['methodology_id' => $methodology->id]);
        }

        return $methodology;
    }

    private function url(): string
    {
        return "/api/v1/organizations/{$this->organization->id}/methodologies";
    }

    #[Test]
    public function manager_sees_all_org_methodologies_and_defaults()
    {
        $methodologyA = $this->createMethodology('Scrum');
        $methodologyB = $this->createMethodology('Kanban');

        Sanctum::actingAs($this->manager);

        $response = $this->getJson($this->url());

        $response->assertOk()->assertJson(['success' => true]);

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($methodologyA->id, $ids);
        $this->assertContains($methodologyB->id, $ids);
        $this->assertContains($this->defaultMethodology->id, $ids);
    }

    #[Test]
    public function employee_sees_default_methodology()
    {
        Sanctum::actingAs($this->employee);

        $response = $this->getJson($this->url());

        $response->assertOk()->assertJson(['success' => true]);

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($this->defaultMethodology->id, $ids);
    }

    #[Test]
    public function employee_sees_methodology_assigned_to_their_team()
    {
        $this->team->users()->attach($this->employee);

        $methodology = $this->createMethodology('Scrum', $this->team);

        Sanctum::actingAs($this->employee);

        $response = $this->getJson($this->url());

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($methodology->id, $ids);
    }

    #[Test]
    public function employee_does_not_see_methodology_assigned_to_other_team()
    {
        $otherTeam = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team',
            'organization_id' => $this->organization->id,
            'methodology_id' => $this->defaultMethodology->id,
        ]);

        $methodology = $this->createMethodology('Scrum', $otherTeam);

        // employee is NOT in $otherTeam
        Sanctum::actingAs($this->employee);

        $response = $this->getJson($this->url());

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($methodology->id, $ids);
    }

    #[Test]
    public function unauthenticated_user_gets_401()
    {
        $response = $this->getJson($this->url());

        $response->assertUnauthorized();
    }

    #[Test]
    public function user_not_in_organization_gets_404()
    {
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);

        $response = $this->getJson($this->url());

        $response->assertNotFound();
    }
}
