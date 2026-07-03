<?php

namespace Tests\Feature\SecondBrain;

use App\Models\Organization;
use App\Models\SecondBrainInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecondBrainControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    /** @return array{0: User, 1: Organization} */
    private function managerFor(string $suffix): array
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => "Org {$suffix}", 'slug' => 'org-'.strtolower($suffix).'-'.uniqid()]);
        $org->users()->attach($user->id, ['role' => 'manager']);

        return [$user, $org];
    }

    #[Test]
    public function a_manager_can_enable_the_second_brain_for_their_org(): void
    {
        [$user, $org] = $this->managerFor('A');
        Sanctum::actingAs($user, ['*']);

        $res = $this->postJson("/api/v1/brain/instances/{$org->id}/enable", [
            'claude_auth_type' => 'oauth',
            'claude_auth_token' => 'oauth-secret-123',
        ]);

        $res->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.status', SecondBrainInstance::STATUS_PENDING)
            ->assertJsonPath('data.container_name', "second-brain-org{$org->id}");

        $this->assertDatabaseHas('second_brain_instances', [
            'organization_id' => $org->id,
            'enabled' => true,
            'claude_auth_type' => 'oauth',
        ]);
    }

    #[Test]
    public function enable_never_returns_the_secrets(): void
    {
        [$user, $org] = $this->managerFor('A');
        Sanctum::actingAs($user, ['*']);

        $res = $this->postJson("/api/v1/brain/instances/{$org->id}/enable", [
            'claude_auth_type' => 'oauth',
            'claude_auth_token' => 'super-secret-token',
        ]);

        $body = $res->getContent();
        $this->assertStringNotContainsString('super-secret-token', $body);
        $this->assertStringNotContainsString('token_ciphertext', $body);
        $this->assertStringNotContainsString('claude_auth_ciphertext', $body);
    }

    #[Test]
    public function enable_persists_the_credential_encrypted(): void
    {
        [$user, $org] = $this->managerFor('A');
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/v1/brain/instances/{$org->id}/enable", [
            'claude_auth_type' => 'api_key',
            'claude_auth_token' => 'sk-ant-plain-999',
        ])->assertOk();

        $raw = DB::table('second_brain_instances')->where('organization_id', $org->id)->first();
        $this->assertNotSame('sk-ant-plain-999', $raw->claude_auth_ciphertext);
        $this->assertNotNull($raw->token_ciphertext);
    }

    #[Test]
    public function enable_requires_a_credential_on_first_enable(): void
    {
        [$user, $org] = $this->managerFor('A');
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/v1/brain/instances/{$org->id}/enable", [])
            ->assertStatus(422);
    }

    #[Test]
    public function a_non_manager_cannot_enable(): void
    {
        $employee = User::factory()->create();
        $org = Organization::create(['name' => 'Org E', 'slug' => 'org-e-'.uniqid()]);
        $org->users()->attach($employee->id, ['role' => 'employee']);
        Sanctum::actingAs($employee, ['*']);

        $this->postJson("/api/v1/brain/instances/{$org->id}/enable", [
            'claude_auth_type' => 'oauth',
            'claude_auth_token' => 'x-secret-123',
        ])->assertStatus(403);
    }

    #[Test]
    public function a_manager_cannot_touch_an_org_they_do_not_manage(): void
    {
        [$userA] = $this->managerFor('A');
        [, $orgB] = $this->managerFor('B');
        Sanctum::actingAs($userA, ['*']);

        $this->postJson("/api/v1/brain/instances/{$orgB->id}/enable", [
            'claude_auth_type' => 'oauth',
            'claude_auth_token' => 'x-secret-123',
        ])->assertStatus(403);

        $this->getJson("/api/v1/brain/instances/{$orgB->id}")->assertStatus(403);
    }

    #[Test]
    public function disable_revokes_the_token(): void
    {
        [$user, $org] = $this->managerFor('A');
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/v1/brain/instances/{$org->id}/enable", [
            'claude_auth_type' => 'oauth',
            'claude_auth_token' => 'x-secret-123',
        ])->assertOk();

        $tokenId = SecondBrainInstance::where('organization_id', $org->id)->value('token_id');
        $this->assertNotNull(PersonalAccessToken::find($tokenId));

        $this->postJson("/api/v1/brain/instances/{$org->id}/disable")
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->assertNull(PersonalAccessToken::find($tokenId));
    }

    #[Test]
    public function status_is_disabled_for_a_never_provisioned_org(): void
    {
        [$user, $org] = $this->managerFor('A');
        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/v1/brain/instances/{$org->id}")
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.status', SecondBrainInstance::STATUS_DISABLED);
    }
}
