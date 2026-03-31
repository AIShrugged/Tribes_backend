<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Organization;
use App\Models\OrganizationIssueType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizationIssueTypesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function organization_show_includes_resolved_issue_types(): void
    {
        $manager = User::factory()->create();
        $organization = Organization::create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);
        $organization->users()->attach($manager->id, ['role' => 'manager']);

        $profile = AgentProfile::create([
            'key' => 'backend-workflow',
            'name' => 'Backend workflow',
            'description' => null,
            'system_prompt' => 'Use the backend repository context.',
            'allowed_tools' => [],
            'allowed_outbound_hosts' => [],
            'sandbox_profile' => null,
            'execution_mode' => 'inline',
            'enabled' => true,
        ]);

        OrganizationIssueType::query()
            ->whereNull('organization_id')
            ->where('key', 'backend')
            ->firstOrFail()
            ->update([
                'agent_profile_id' => $profile->id,
            ]);

        Sanctum::actingAs($manager);

        $response = $this->getJson("/api/v1/organizations/{$organization->id}")
            ->assertOk();

        $issueTypes = collect($response->json('data.issue_types'));

        $backend = $issueTypes->firstWhere('key', 'backend');

        $this->assertNotNull($backend);
        $this->assertSame($profile->id, $backend['agent_profile']['id']);
        $this->assertSame($profile->name, $backend['agent_profile']['name']);
        $this->assertSame('development', $backend['base_type']);
    }

    #[Test]
    public function organization_update_syncs_issue_type_overrides(): void
    {
        $manager = User::factory()->create();
        $organization = Organization::create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);
        $organization->users()->attach($manager->id, ['role' => 'manager']);

        $profile = AgentProfile::create([
            'key' => 'frontend-workflow',
            'name' => 'Frontend workflow',
            'description' => null,
            'system_prompt' => 'Use the frontend repository context.',
            'allowed_tools' => [],
            'allowed_outbound_hosts' => [],
            'sandbox_profile' => null,
            'execution_mode' => 'inline',
            'enabled' => true,
        ]);

        Sanctum::actingAs($manager);

        $response = $this->putJson("/api/v1/organizations/{$organization->id}", [
            'issue_types' => [
                [
                    'key' => 'frontend',
                    'name' => 'Frontend',
                    'base_type' => 'development',
                    'agent_profile_id' => $profile->id,
                    'is_active' => true,
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('organization_issue_types', [
            'organization_id' => $organization->id,
            'key' => 'frontend',
            'agent_profile_id' => $profile->id,
        ]);

        $issueTypes = collect($response->json('data.issue_types'));
        $frontend = $issueTypes->firstWhere('key', 'frontend');

        $this->assertNotNull($frontend);
        $this->assertSame($profile->id, $frontend['agent_profile']['id']);
        $this->assertSame($profile->name, $frontend['agent_profile']['name']);
    }
}
