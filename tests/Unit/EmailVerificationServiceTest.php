<?php

namespace Tests\Unit;

use App\Models\EmailVerification;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailVerificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmailVerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EmailVerificationService::class);
    }

    #[Test]
    public function token_generation_is_unique()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $verification1 = $this->service->createVerification($user1);
        $verification2 = $this->service->createVerification($user2);

        $this->assertNotEquals($verification1->token, $verification2->token);
        $this->assertNotEquals($verification1->plain_token, $verification2->plain_token);
    }

    #[Test]
    public function token_expiry_calculated_correctly()
    {
        config(['app.email_verification_expiry' => 30]);

        $user = User::factory()->create();
        $verification = $this->service->createVerification($user);

        $expectedExpiry = now()->addMinutes(30);
        $actualExpiry = $verification->expires_at;

        // Allow 1 second tolerance
        $this->assertTrue(
            abs($expectedExpiry->timestamp - $actualExpiry->timestamp) <= 1,
            "Token expiry should be 30 minutes from now"
        );
    }

    #[Test]
    public function verification_record_created()
    {
        $user = User::factory()->create();
        $verification = $this->service->createVerification($user);

        $this->assertDatabaseHas('email_verifications', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        $this->assertNotNull($verification->token);
        $this->assertNotNull($verification->expires_at);
        $this->assertNull($verification->verified_at);
    }

    #[Test]
    public function cleanup_removes_only_expired_records()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        // Create expired verification
        $expiredVerification = $this->service->createVerification($user1);
        $expiredVerification->update(['expires_at' => now()->subHour()]);

        // Create valid verification
        $validVerification = $this->service->createVerification($user2);

        // Create verified verification
        $verifiedVerification = $this->service->createVerification($user3);
        $verifiedVerification->markAsVerified();

        $count = $this->service->cleanupExpired();

        // Should remove expired and verified records
        $this->assertEquals(2, $count);

        $this->assertDatabaseMissing('email_verifications', [
            'id' => $expiredVerification->id,
        ]);

        $this->assertDatabaseMissing('email_verifications', [
            'id' => $verifiedVerification->id,
        ]);

        $this->assertDatabaseHas('email_verifications', [
            'id' => $validVerification->id,
        ]);
    }

    #[Test]
    public function creating_new_verification_invalidates_existing_ones()
    {
        $user = User::factory()->create();

        // Create first verification
        $verification1 = $this->service->createVerification($user);

        // Create second verification
        $verification2 = $this->service->createVerification($user);

        // First verification should be deleted
        $this->assertDatabaseMissing('email_verifications', [
            'id' => $verification1->id,
        ]);

        // Second verification should exist
        $this->assertDatabaseHas('email_verifications', [
            'id' => $verification2->id,
        ]);
    }

    #[Test]
    public function token_is_hashed_before_storage()
    {
        $user = User::factory()->create();
        $verification = $this->service->createVerification($user);

        // Plain token should be 64 characters
        $this->assertEquals(64, strlen($verification->plain_token));

        // Stored token should be SHA-256 hash (64 characters in hex)
        $this->assertEquals(64, strlen($verification->token));

        // They should not be equal
        $this->assertNotEquals($verification->plain_token, $verification->token);

        // Verify hash
        $expectedHash = hash('sha256', $verification->plain_token);
        $this->assertEquals($expectedHash, $verification->token);
    }

    #[Test]
    public function verify_token_marks_user_email_as_verified()
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $verification = $this->service->createVerification($user);

        $result = $this->service->verifyToken($verification->plain_token);

        $this->assertTrue($result);

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);

        $verification->refresh();
        $this->assertNotNull($verification->verified_at);
    }

    #[Test]
    public function verify_token_fails_with_invalid_token()
    {
        $result = $this->service->verifyToken('invalid-token-123');

        $this->assertFalse($result);
    }

    #[Test]
    public function is_token_expired_returns_true_for_expired_token()
    {
        $user = User::factory()->create();
        $verification = $this->service->createVerification($user);
        $verification->update(['expires_at' => now()->subHour()]);

        $isExpired = $this->service->isTokenExpired($verification->plain_token);

        $this->assertTrue($isExpired);
    }

    #[Test]
    public function is_token_expired_returns_false_for_valid_token()
    {
        $user = User::factory()->create();
        $verification = $this->service->createVerification($user);

        $isExpired = $this->service->isTokenExpired($verification->plain_token);

        $this->assertFalse($isExpired);
    }
}
