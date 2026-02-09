<?php

namespace Tests\Feature;

use App\Enums\InviteStatus;
use App\Jobs\SendEmailJob;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\TeamInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamInviteTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;
    protected User $employee;
    protected Organization $organization;
    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        // Create organization
        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
        ]);

        // Create default methodology
        $methodology = Methodology::where('is_default', true)->first();
        if (!$methodology) {
            $methodology = Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ]);
        }

        // Create team
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'organization_id' => $this->organization->id,
            'methodology_id' => $methodology->id,
        ]);

        // Create users
        $this->manager = User::factory()->create();
        $this->employee = User::factory()->create();

        // Attach manager to organization
        $this->organization->users()->attach($this->manager, ['role' => 'manager']);

        // Attach employee to organization
        $this->organization->users()->attach($this->employee, ['role' => 'employee']);
    }

    /** @test */
    public function manager_can_send_invite()
    {
        Queue::fake();
        Sanctum::actingAs($this->manager);

        $response = $this->postJson("/api/v1/teams/{$this->team->id}/invites", [
            'email' => 'newuser@example.com',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Invitation sent',
            ])
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'email',
                    'status',
                    'expires_at',
                    'created_at',
                ],
            ]);

        $this->assertDatabaseHas('invites', [
            'team_id' => $this->team->id,
            'email' => 'newuser@example.com',
            'status' => InviteStatus::PENDING->value,
        ]);

        Queue::assertPushed(SendEmailJob::class);
    }

    /** @test */
    public function non_manager_cannot_send_invite()
    {
        Sanctum::actingAs($this->employee);

        $response = $this->postJson("/api/v1/teams/{$this->team->id}/invites", [
            'email' => 'newuser@example.com',
        ]);

        $response->assertStatus(404);
    }

    /** @test */
    public function cannot_invite_existing_team_member()
    {
        Sanctum::actingAs($this->manager);

        // Add employee to team
        $this->team->users()->attach($this->employee);

        $response = $this->postJson("/api/v1/teams/{$this->team->id}/invites", [
            'email' => $this->employee->email,
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
            ]);
    }

    /** @test */
    public function cannot_send_duplicate_pending_invite()
    {
        Sanctum::actingAs($this->manager);

        // First invite
        $this->postJson("/api/v1/teams/{$this->team->id}/invites", [
            'email' => 'newuser@example.com',
        ]);

        // Duplicate invite
        $response = $this->postJson("/api/v1/teams/{$this->team->id}/invites", [
            'email' => 'newuser@example.com',
        ]);

        $response->assertStatus(409);
    }

    /** @test */
    public function manager_can_view_invites_list()
    {
        Sanctum::actingAs($this->manager);

        // Create some invites
        $service = app(TeamInvitationService::class);
        $service->createInvite($this->team, 'user1@example.com', $this->manager);
        $service->createInvite($this->team, 'user2@example.com', $this->manager);
        $service->createInvite($this->team, 'user3@example.com', $this->manager);

        $response = $this->getJson("/api/v1/teams/{$this->team->id}/invites");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    /** @test */
    public function manager_can_view_invites_with_pagination()
    {
        Sanctum::actingAs($this->manager);

        // Create some invites
        $service = app(TeamInvitationService::class);
        for ($i = 0; $i < 15; $i++) {
            $service->createInvite($this->team, "user{$i}@example.com", $this->manager);
        }

        $response = $this->getJson("/api/v1/teams/{$this->team->id}/invites?limit=5&offset=0");

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data');

        $this->assertEquals(15, $response->headers->get('Items-Count'));
    }

    /** @test */
    public function manager_can_cancel_invite()
    {
        Sanctum::actingAs($this->manager);

        $service = app(TeamInvitationService::class);
        $invite = $service->createInvite($this->team, 'newuser@example.com', $this->manager);

        $response = $this->deleteJson("/api/v1/teams/{$this->team->id}/invites/{$invite->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Invitation cancelled',
            ]);

        $invite->refresh();
        $this->assertEquals(InviteStatus::CANCELLED, $invite->status);
    }

    /** @test */
    public function existing_user_can_accept_invite()
    {
        config(['app.frontend_url' => 'https://example.com']);

        $existingUser = User::factory()->create(['email' => 'existing@example.com']);

        $service = app(TeamInvitationService::class);
        $invite = $service->createInvite($this->team, 'existing@example.com', $this->manager);

        $response = $this->get("/api/v1/invites/accept/{$invite->plain_token}");

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('https://example.com/invite-accepted', $location);
        $this->assertStringContainsString('status=success', $location);

        // Check user was added to team and organization
        $existingUser->refresh();
        $this->assertTrue($existingUser->belongsToTeam($this->team));
        $this->assertTrue($existingUser->isOrganizationMember($this->organization));
    }

    /** @test */
    public function non_existing_user_redirects_to_register()
    {
        config(['app.frontend_url' => 'https://example.com']);

        $service = app(TeamInvitationService::class);
        $invite = $service->createInvite($this->team, 'newuser@example.com', $this->manager);

        $response = $this->get("/api/v1/invites/accept/{$invite->plain_token}");

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('https://example.com/register', $location);
        $this->assertStringContainsString('invite=' . $invite->plain_token, $location);
        $this->assertStringContainsString('email=newuser%40example.com', $location);
    }

    /** @test */
    public function expired_invite_cannot_be_accepted()
    {
        config(['app.frontend_url' => 'https://example.com']);

        $service = app(TeamInvitationService::class);
        $invite = $service->createInvite($this->team, 'newuser@example.com', $this->manager);

        // Manually expire the invite
        $invite->update(['expires_at' => now()->subHour()]);

        $response = $this->get("/api/v1/invites/accept/{$invite->plain_token}");

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('status=error', $location);
        $this->assertStringContainsString('reason=expired', $location);
    }

    /** @test */
    public function cancelled_invite_cannot_be_accepted()
    {
        config(['app.frontend_url' => 'https://example.com']);

        $service = app(TeamInvitationService::class);
        $invite = $service->createInvite($this->team, 'newuser@example.com', $this->manager);

        // Cancel the invite
        $invite->markAsCancelled();

        $response = $this->get("/api/v1/invites/accept/{$invite->plain_token}");

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('status=error', $location);
        $this->assertStringContainsString('reason=cancelled', $location);
    }

    /** @test */
    public function invalid_token_returns_error()
    {
        config(['app.frontend_url' => 'https://example.com']);

        $response = $this->get('/api/v1/invites/accept/invalid-token-12345');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('status=error', $location);
        $this->assertStringContainsString('reason=invalid_token', $location);
    }

    /** @test */
    public function registration_with_invite_token_joins_team()
    {
        Queue::fake();

        $service = app(TeamInvitationService::class);
        $invite = $service->createInvite($this->team, 'newuser@example.com', $this->manager);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'invite' => $invite->plain_token,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'invite_accepted' => true,
                'team_id' => $this->team->id,
                'organization_id' => $this->organization->id,
                'email_verification_sent' => false,
            ]);

        $user = User::where('email', 'newuser@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->belongsToTeam($this->team));
        $this->assertTrue($user->isOrganizationMember($this->organization));
        $this->assertNotNull($user->email_verified_at);

        $invite->refresh();
        $this->assertEquals(InviteStatus::ACCEPTED, $invite->status);
    }

    /** @test */
    public function registration_without_invite_requires_email_verification()
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'email_verification_sent' => true,
            ]);

        $user = User::where('email', 'newuser@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);

        Queue::assertPushed(SendEmailJob::class);
    }

    /** @test */
    public function registration_with_invalid_invite_still_registers_but_requires_verification()
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'invite' => 'invalid-token',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'email_verification_sent' => true,
            ])
            ->assertJsonMissing([
                'invite_accepted' => true,
            ]);

        $user = User::where('email', 'newuser@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);
    }

    /** @test */
    public function registration_with_mismatched_email_does_not_accept_invite()
    {
        Queue::fake();

        $service = app(TeamInvitationService::class);
        $invite = $service->createInvite($this->team, 'invited@example.com', $this->manager);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New User',
            'email' => 'different@example.com',
            'password' => 'password123',
            'invite' => $invite->plain_token,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'email_verification_sent' => true,
            ])
            ->assertJsonMissing([
                'invite_accepted' => true,
            ]);

        $user = User::where('email', 'different@example.com')->first();
        $this->assertFalse($user->belongsToTeam($this->team));
    }

    /** @test */
    public function non_manager_cannot_view_invites()
    {
        Sanctum::actingAs($this->employee);

        $response = $this->getJson("/api/v1/teams/{$this->team->id}/invites");

        $response->assertStatus(404);
    }

    /** @test */
    public function non_manager_cannot_cancel_invite()
    {
        Sanctum::actingAs($this->employee);

        $service = app(TeamInvitationService::class);
        $invite = $service->createInvite($this->team, 'newuser@example.com', $this->manager);

        $response = $this->deleteJson("/api/v1/teams/{$this->team->id}/invites/{$invite->id}");

        $response->assertStatus(404);
    }

    /** @test */
    public function unauthenticated_user_cannot_manage_invites()
    {
        $response = $this->getJson("/api/v1/teams/{$this->team->id}/invites");
        $response->assertStatus(401);

        $response = $this->postJson("/api/v1/teams/{$this->team->id}/invites", [
            'email' => 'newuser@example.com',
        ]);
        $response->assertStatus(401);
    }
}
