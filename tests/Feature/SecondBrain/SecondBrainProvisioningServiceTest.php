<?php

namespace Tests\Feature\SecondBrain;

use App\Models\Organization;
use App\Models\SecondBrainInstance;
use App\Models\User;
use App\Services\SecondBrain\SecondBrainProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecondBrainProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    private function service(): SecondBrainProvisioningService
    {
        return app(SecondBrainProvisioningService::class);
    }

    private function org(string $suffix = 'A'): Organization
    {
        return Organization::create(['name' => "Org {$suffix}", 'slug' => 'org-'.strtolower($suffix).'-'.uniqid()]);
    }

    #[Test]
    public function issue_token_creates_a_single_org_manager_service_user_with_mcp_ability(): void
    {
        $org = $this->org();

        $token = $this->service()->issueToken($org);
        $serviceUser = $token->accessToken->tokenable;

        $this->assertInstanceOf(User::class, $serviceUser);
        $this->assertSame("second-brain+org{$org->id}@brain.tribesmcp.local", $serviceUser->email);
        // Manager of exactly this one org (so the MCP tenant resolves unambiguously).
        $this->assertSame([$org->id], $serviceUser->organizations()->pluck('organizations.id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame('manager', $serviceUser->organizations()->first()->pivot->role);
        $this->assertSame(['mcp'], $token->accessToken->abilities);
    }

    #[Test]
    public function issue_token_rotates_the_previous_token(): void
    {
        $org = $this->org();
        $svc = $this->service();

        $first = $svc->issueToken($org);
        $second = $svc->issueToken($org);

        // Old token row is gone; exactly one remains.
        $this->assertNull(PersonalAccessToken::find($first->accessToken->getKey()));
        $this->assertSame(1, PersonalAccessToken::where('name', 'second-brain')->count());
        $this->assertNotSame($first->plainTextToken, $second->plainTextToken);
    }

    #[Test]
    public function enable_stores_encrypted_secrets_and_marks_pending(): void
    {
        $org = $this->org();

        $instance = $this->service()->enable($org, SecondBrainInstance::AUTH_OAUTH, 'oauth-secret-123');

        $this->assertTrue($instance->enabled);
        $this->assertSame(SecondBrainInstance::STATUS_PENDING, $instance->status);
        $this->assertSame(SecondBrainInstance::AUTH_OAUTH, $instance->claude_auth_type);
        $this->assertSame("second-brain-org{$org->id}", $instance->container_name);
        $this->assertSame("second-brain-state-org{$org->id}", $instance->volume_name);

        // Secrets are encrypted at rest (raw column != plaintext).
        $raw = DB::table('second_brain_instances')->where('id', $instance->id)->first();
        $this->assertNotSame('oauth-secret-123', $raw->claude_auth_ciphertext);
        $this->assertNotNull($raw->token_ciphertext);
        // But decrypt back through the cast.
        $this->assertSame('oauth-secret-123', $instance->fresh()->claude_auth_ciphertext);
    }

    #[Test]
    public function enable_accepts_the_api_key_auth_type(): void
    {
        $org = $this->org();

        $instance = $this->service()->enable($org, SecondBrainInstance::AUTH_API_KEY, 'sk-ant-xyz-456');

        $this->assertSame(SecondBrainInstance::AUTH_API_KEY, $instance->claude_auth_type);
        $this->assertSame('sk-ant-xyz-456', $instance->fresh()->claude_auth_ciphertext);
    }

    #[Test]
    public function enable_is_idempotent_and_does_not_rotate_the_token_when_running(): void
    {
        $org = $this->org();
        $svc = $this->service();

        $first = $svc->enable($org, SecondBrainInstance::AUTH_OAUTH, 'cred-1');
        // Simulate the reconciler having started the container.
        $first->update(['status' => SecondBrainInstance::STATUS_RUNNING]);
        $originalTokenId = $first->token_id;
        $originalToken = $first->fresh()->token_ciphertext;

        $again = $svc->enable($org, SecondBrainInstance::AUTH_OAUTH, 'cred-2');

        // Same token (a fresh one would break the live container), updated credential.
        $this->assertSame($originalTokenId, $again->token_id);
        $this->assertSame($originalToken, $again->fresh()->token_ciphertext);
        $this->assertSame('cred-2', $again->fresh()->claude_auth_ciphertext);
        $this->assertSame(1, SecondBrainInstance::where('organization_id', $org->id)->count());
    }

    #[Test]
    public function disable_flips_state_and_revokes_the_token(): void
    {
        $org = $this->org();
        $svc = $this->service();

        $instance = $svc->enable($org, SecondBrainInstance::AUTH_OAUTH, 'cred');
        $tokenId = $instance->token_id;
        $this->assertNotNull(PersonalAccessToken::find($tokenId));

        $disabled = $svc->disable($org);

        $this->assertFalse($disabled->enabled);
        $this->assertSame(SecondBrainInstance::STATUS_STOPPING, $disabled->status);
        $this->assertNull($disabled->token_id);
        $this->assertNull($disabled->fresh()->token_ciphertext);
        // Token revoked → an orphaned container loses MCP access.
        $this->assertNull(PersonalAccessToken::find($tokenId));
    }
}
