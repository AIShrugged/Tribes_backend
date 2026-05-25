<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_and_lists_agent_profiles(): void
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $organization->users()->attach($user->id, ['role' => 'manager']);

        $this->actingAs($user)->postJson('/api/v1/agent-profiles', [
            'key' => 'github-reviewer',
            'name' => 'GitHub Reviewer',
            'system_prompt' => 'Review repositories.',
            'config_schema' => [
                'type' => 'object',
                'properties' => [
                    'scan_mode' => [
                        'type' => 'string',
                    ],
                ],
            ],
            'task_payload_schema' => [
                'type' => 'object',
                'required' => ['provider', 'owner', 'repo'],
                'properties' => [
                    'provider' => ['type' => 'string'],
                    'owner' => ['type' => 'string'],
                    'repo' => ['type' => 'string'],
                ],
            ],
            'execution_mode' => 'isolated',
            'allowed_tools' => ['get_current_user'],
            'allowed_outbound_hosts' => ['api.github.com'],
            'enabled' => true,
        ])->assertStatus(201)
            ->assertJsonPath('data.key', 'github-reviewer')
            ->assertJsonPath('data.execution_mode', 'isolated');

        $this->actingAs($user)
            ->getJson('/api/v1/agent-profiles')
            ->assertStatus(200)
            ->assertJsonPath('data.0.key', 'github-reviewer');
    }

    #[Test]
    public function it_validates_payload_against_profile_json_schema(): void
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $organization->users()->attach($user->id, ['role' => 'manager']);
        $profile = AgentProfile::create([
            'key' => 'github-reviewer',
            'name' => 'GitHub Reviewer',
            'task_payload_schema' => [
                'type' => 'object',
                'required' => ['provider', 'owner', 'repo'],
                'properties' => [
                    'provider' => ['type' => 'string'],
                    'owner' => ['type' => 'string'],
                    'repo' => ['type' => 'string'],
                ],
            ],
        ]);

        $this->actingAs($user)->postJson("/api/v1/agent-profiles/{$profile->id}/validate-payload", [
            'payload' => [
                'provider' => 'github',
                'owner' => 'acme',
                'repo' => 'api',
            ],
        ])->assertStatus(200)
            ->assertJsonPath('data.valid', true);

        $this->actingAs($user)->postJson("/api/v1/agent-profiles/{$profile->id}/validate-payload", [
            'payload' => [
                'provider' => 'github',
                'owner' => 'acme',
            ],
        ])->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'INVALID_JSON_PAYLOAD');
    }

    #[Test]
    public function profile_tools_endpoint_handles_scalar_allowed_tools(): void
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $organization->users()->attach($user->id, ['role' => 'manager']);

        $profile = AgentProfile::create([
            'key' => 'legacy-profile',
            'name' => 'Legacy Profile',
            'allowed_tools' => 'get_user_info',
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/agent-profiles/{$profile->id}/tools")
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'get_user_info'])
            ->assertJsonMissing(['name' => 'list_workspaces']);
    }

    #[Test]
    public function non_manager_cannot_manage_agent_profiles(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/agent-profiles')
            ->assertStatus(403);

        $this->actingAs($user)->postJson('/api/v1/agent-profiles', [
            'key' => 'github-reviewer',
            'name' => 'GitHub Reviewer',
        ])->assertStatus(403);
    }
}
