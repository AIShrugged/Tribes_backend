<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IssueCodeLookupTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithOrganization(): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Dev_coding', 'slug' => 'dev-coding']);
        $organization->users()->attach($user->id, ['role' => 'manager']);

        return [$user, $organization];
    }

    #[Test]
    public function index_filters_by_exact_code(): void
    {
        [$user, $org] = $this->memberWithOrganization();
        Issue::create(['organization_id' => $org->id, 'name' => 'First', 'type' => 'development']);   // DEV-1
        Issue::create(['organization_id' => $org->id, 'name' => 'Second', 'type' => 'development']);  // DEV-2

        $this->actingAs($user)
            ->getJson('/api/v1/issues?code=DEV-2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'DEV-2');
    }

    #[Test]
    public function index_filter_by_code_is_case_insensitive(): void
    {
        [$user, $org] = $this->memberWithOrganization();
        Issue::create(['organization_id' => $org->id, 'name' => 'First', 'type' => 'development']);

        $this->actingAs($user)
            ->getJson('/api/v1/issues?code=dev-1')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'DEV-1');
    }

    #[Test]
    public function search_matches_the_code(): void
    {
        [$user, $org] = $this->memberWithOrganization();
        Issue::create(['organization_id' => $org->id, 'name' => 'First', 'type' => 'development']);
        Issue::create(['organization_id' => $org->id, 'name' => 'Second', 'type' => 'development']);

        $this->actingAs($user)
            ->getJson('/api/v1/issues?search=DEV')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function it_resolves_an_issue_by_code(): void
    {
        [$user, $org] = $this->memberWithOrganization();
        Issue::create(['organization_id' => $org->id, 'name' => 'First', 'type' => 'development']);

        $this->actingAs($user)
            ->getJson('/api/v1/issues/by-code/dev-1')
            ->assertOk()
            ->assertJsonPath('data.code', 'DEV-1');
    }

    #[Test]
    public function by_code_returns_404_for_an_unknown_code(): void
    {
        [$user] = $this->memberWithOrganization();

        $this->actingAs($user)
            ->getJson('/api/v1/issues/by-code/DEV-999')
            ->assertNotFound();
    }

    #[Test]
    public function by_code_hides_issues_the_user_cannot_see(): void
    {
        [$user] = $this->memberWithOrganization();

        $foreignOrg = Organization::create(['name' => 'Auchan', 'slug' => 'auchan']);
        Issue::create(['organization_id' => $foreignOrg->id, 'name' => 'Hidden', 'type' => 'organization']); // AUC-1

        $this->actingAs($user)
            ->getJson('/api/v1/issues/by-code/AUC-1')
            ->assertNotFound();
    }

    #[Test]
    public function it_previews_the_generated_organization_code(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/organizations/preview-code?name='.urlencode('Auchan'))
            ->assertOk()
            ->assertJsonPath('data.code', 'AUC');
    }
}
