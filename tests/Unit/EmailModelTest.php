<?php

namespace Tests\Unit;

use App\Enums\EmailStatus;
use App\Models\Email;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_creates_email_with_pending_status()
    {
        $email = Email::create([
            'from' => 'sender@example.com',
            'from_name' => 'John Doe',
            'to' => ['recipient@example.com'],
            'subject' => 'Test Subject',
            'html_body' => '<h1>Test</h1>',
            'status' => EmailStatus::PENDING,
            'provider' => 'unisender_go',
        ]);

        $this->assertDatabaseHas('emails', [
            'from' => 'sender@example.com',
            'status' => 'pending',
        ]);

        $this->assertTrue($email->isPending());
    }

    /** @test */
    public function it_marks_email_as_sent()
    {
        $email = Email::create([
            'from' => 'sender@example.com',
            'to' => ['recipient@example.com'],
            'subject' => 'Test Subject',
            'html_body' => '<h1>Test</h1>',
            'status' => EmailStatus::PENDING,
            'provider' => 'unisender_go',
        ]);

        $email->markAsSent('message-123');

        $this->assertTrue($email->isSent());
        $this->assertEquals('message-123', $email->provider_message_id);
        $this->assertNotNull($email->sent_at);
    }

    /** @test */
    public function it_marks_email_as_sent_without_message_id()
    {
        $email = Email::create([
            'from' => 'sender@example.com',
            'to' => ['recipient@example.com'],
            'subject' => 'Test Subject',
            'html_body' => '<h1>Test</h1>',
            'status' => EmailStatus::PENDING,
            'provider' => 'unisender_go',
        ]);

        $email->markAsSent();

        $this->assertTrue($email->isSent());
        $this->assertNull($email->provider_message_id);
        $this->assertNotNull($email->sent_at);
    }

    /** @test */
    public function it_marks_email_as_failed()
    {
        $email = Email::create([
            'from' => 'sender@example.com',
            'to' => ['recipient@example.com'],
            'subject' => 'Test Subject',
            'html_body' => '<h1>Test</h1>',
            'status' => EmailStatus::PENDING,
            'provider' => 'unisender_go',
        ]);

        $email->markAsFailed('Connection timeout');

        $this->assertTrue($email->isFailed());
        $this->assertEquals('Connection timeout', $email->error_message);
    }

    /** @test */
    public function it_increments_retry_count()
    {
        $email = Email::create([
            'from' => 'sender@example.com',
            'to' => ['recipient@example.com'],
            'subject' => 'Test Subject',
            'html_body' => '<h1>Test</h1>',
            'status' => EmailStatus::PENDING,
            'provider' => 'unisender_go',
        ]);

        $this->assertEquals(0, $email->retry_count);

        $email->incrementRetryCount();
        $this->assertEquals(1, $email->fresh()->retry_count);

        $email->incrementRetryCount();
        $this->assertEquals(2, $email->fresh()->retry_count);
    }

    /** @test */
    public function it_casts_to_array_properly()
    {
        $email = Email::create([
            'from' => 'sender@example.com',
            'to' => ['recipient1@example.com', 'recipient2@example.com'],
            'subject' => 'Test Subject',
            'html_body' => '<h1>Test</h1>',
            'attachments' => [
                ['path' => '/tmp/file.pdf', 'name' => 'file.pdf']
            ],
            'status' => EmailStatus::PENDING,
            'provider' => 'unisender_go',
        ]);

        $this->assertIsArray($email->to);
        $this->assertCount(2, $email->to);
        $this->assertIsArray($email->attachments);
        $this->assertCount(1, $email->attachments);
    }

    /** @test */
    public function it_casts_status_to_enum()
    {
        $email = Email::create([
            'from' => 'sender@example.com',
            'to' => ['recipient@example.com'],
            'subject' => 'Test Subject',
            'html_body' => '<h1>Test</h1>',
            'status' => EmailStatus::SENT,
            'provider' => 'unisender_go',
        ]);

        $this->assertInstanceOf(EmailStatus::class, $email->status);
        $this->assertEquals(EmailStatus::SENT, $email->status);
    }

    /** @test */
    public function it_checks_sent_status()
    {
        $email = Email::create([
            'from' => 'sender@example.com',
            'to' => ['recipient@example.com'],
            'subject' => 'Test Subject',
            'html_body' => '<h1>Test</h1>',
            'status' => EmailStatus::SENT,
            'provider' => 'unisender_go',
        ]);

        $this->assertTrue($email->isSent());
        $this->assertFalse($email->isFailed());
        $this->assertFalse($email->isPending());
    }

    /** @test */
    public function it_checks_failed_status()
    {
        $email = Email::create([
            'from' => 'sender@example.com',
            'to' => ['recipient@example.com'],
            'subject' => 'Test Subject',
            'html_body' => '<h1>Test</h1>',
            'status' => EmailStatus::FAILED,
            'provider' => 'unisender_go',
        ]);

        $this->assertTrue($email->isFailed());
        $this->assertFalse($email->isSent());
        $this->assertFalse($email->isPending());
    }

    /** @test */
    public function it_checks_pending_status()
    {
        $email = Email::create([
            'from' => 'sender@example.com',
            'to' => ['recipient@example.com'],
            'subject' => 'Test Subject',
            'html_body' => '<h1>Test</h1>',
            'status' => EmailStatus::PENDING,
            'provider' => 'unisender_go',
        ]);

        $this->assertTrue($email->isPending());
        $this->assertFalse($email->isSent());
        $this->assertFalse($email->isFailed());
    }
}
