<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Models\EmailVerification;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function verification_email_sent_on_registration()
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['token', 'email_verification_sent']])
            ->assertJsonPath('data.email_verification_sent', true);

        Queue::assertPushed(SendEmailJob::class);

        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
            'email_verified_at' => null,
        ]);

        $this->assertDatabaseHas('email_verifications', [
            'email' => 'john@example.com',
        ]);
    }

    #[Test]
    public function user_can_verify_email_with_valid_token()
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $service = app(EmailVerificationService::class);
        $verification = $service->createVerification($user);

        $response = $this->get('/api/v1/auth/email/verify/' . $verification->plain_token);

        $response->assertRedirect();
        $this->assertStringContainsString('status=success', $response->headers->get('Location'));

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
    }

    #[Test]
    public function verification_fails_with_expired_token()
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $service = app(EmailVerificationService::class);
        $verification = $service->createVerification($user);

        // Manually expire the token
        $verification->update(['expires_at' => now()->subHour()]);

        $response = $this->get('/api/v1/auth/email/verify/' . $verification->plain_token);

        $response->assertRedirect();
        $this->assertStringContainsString('status=error', $response->headers->get('Location'));
        $this->assertStringContainsString('reason=expired', $response->headers->get('Location'));

        $user->refresh();
        $this->assertNull($user->email_verified_at);
    }

    #[Test]
    public function verification_fails_with_invalid_token()
    {
        $response = $this->get('/api/v1/auth/email/verify/invalid-token-12345');

        $response->assertRedirect();
        $this->assertStringContainsString('status=error', $response->headers->get('Location'));
        $this->assertStringContainsString('reason=invalid_token', $response->headers->get('Location'));
    }

    #[Test]
    public function user_can_resend_verification_email()
    {
        Queue::fake();

        $user = User::factory()->create(['email_verified_at' => null]);
        $token = $user->createToken('authToken')->plainTextToken;

        $response = $this->postJson('/api/v1/auth/email/resend', [], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Verification email sent',
                'email' => $user->email,
                'expires_in_minutes' => 30,
            ]);

        Queue::assertPushed(SendEmailJob::class);
    }

    #[Test]
    public function resend_throttled_after_multiple_requests()
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $token = $user->createToken('authToken')->plainTextToken;

        $throttled = false;

        // Make multiple requests until we hit the throttle limit
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/v1/auth/email/resend', [], [
                'Authorization' => 'Bearer ' . $token,
            ]);

            if ($response->status() === 429) {
                $throttled = true;
                break;
            }
        }

        // Assert that we were throttled at some point
        $this->assertTrue($throttled, 'Expected to be throttled after multiple requests');
    }

    #[Test]
    public function cannot_resend_if_already_verified()
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $token = $user->createToken('authToken')->plainTextToken;

        $response = $this->postJson('/api/v1/auth/email/resend', [], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Email already verified',
            ]);
    }

    #[Test]
    public function verification_redirects_to_frontend_with_correct_params()
    {
        config(['app.frontend_url' => 'https://example.com']);

        $user = User::factory()->create(['email_verified_at' => null]);
        $service = app(EmailVerificationService::class);
        $verification = $service->createVerification($user);

        $response = $this->get('/api/v1/auth/email/verify/' . $verification->plain_token);

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://example.com/email-verified', $location);
        $this->assertStringContainsString('status=success', $location);
    }

    #[Test]
    public function already_verified_token_returns_error()
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $service = app(EmailVerificationService::class);
        $verification = $service->createVerification($user);

        // Verify once
        $this->get('/api/v1/auth/email/verify/' . $verification->plain_token);

        // Try to verify again
        $response = $this->get('/api/v1/auth/email/verify/' . $verification->plain_token);

        $response->assertRedirect();
        $this->assertStringContainsString('status=error', $response->headers->get('Location'));
        $this->assertStringContainsString('reason=already_verified', $response->headers->get('Location'));
    }
}
