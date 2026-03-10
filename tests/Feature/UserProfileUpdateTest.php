<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'name'     => 'Old Name',
            'password' => Hash::make('current-password'),
        ]);
    }

    /** @test */
    public function user_can_update_name(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->patchJson('/api/v1/users/me', [
            'name' => 'New Name',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email', $this->user->email);

        $this->assertDatabaseHas('users', [
            'id'   => $this->user->id,
            'name' => 'New Name',
        ]);
    }

    /** @test */
    public function user_can_update_password(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user);

        $response = $this->patchJson('/api/v1/users/me', [
            'current_password' => 'current-password',
            'password'         => 'new-password',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->user->refresh();
        $this->assertTrue(Hash::check('new-password', $this->user->password));
        $this->assertFalse(Hash::check('current-password', $this->user->password));
    }

    /** @test */
    public function password_change_dispatches_notification_email(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user);

        $this->patchJson('/api/v1/users/me', [
            'current_password' => 'current-password',
            'password'         => 'new-password',
        ]);

        Queue::assertPushed(SendEmailJob::class);
    }

    /** @test */
    public function user_can_update_name_and_password_together(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user);

        $response = $this->patchJson('/api/v1/users/me', [
            'name'             => 'Updated Name',
            'current_password' => 'current-password',
            'password'         => 'new-password',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Name');

        $this->user->refresh();
        $this->assertEquals('Updated Name', $this->user->name);
        $this->assertTrue(Hash::check('new-password', $this->user->password));
    }

    /** @test */
    public function update_fails_with_wrong_current_password(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->patchJson('/api/v1/users/me', [
            'current_password' => 'wrong-password',
            'password'         => 'new-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('meta.error_code', 'INVALID_CURRENT_PASSWORD');

        // Password must remain unchanged
        $this->user->refresh();
        $this->assertTrue(Hash::check('current-password', $this->user->password));
    }

    /** @test */
    public function update_fails_when_body_is_empty(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->patchJson('/api/v1/users/me', []);

        $response->assertStatus(422);
    }

    /** @test */
    public function update_fails_when_password_is_given_without_current_password(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->patchJson('/api/v1/users/me', [
            'password' => 'new-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    /** @test */
    public function updating_name_only_does_not_dispatch_email_notification(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user);

        $this->patchJson('/api/v1/users/me', ['name' => 'New Name']);

        Queue::assertNotPushed(SendEmailJob::class);
    }

    /** @test */
    public function response_does_not_expose_password_field(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->patchJson('/api/v1/users/me', ['name' => 'New Name']);

        $response->assertStatus(200)
            ->assertJsonMissingPath('data.password');
    }

    /** @test */
    public function unauthenticated_request_is_rejected(): void
    {
        $response = $this->patchJson('/api/v1/users/me', ['name' => 'New Name']);

        $response->assertStatus(401);
    }
}
