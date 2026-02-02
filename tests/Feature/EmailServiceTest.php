<?php

namespace Tests\Feature;

use App\Domain\DTO\EmailDTO;
use App\Models\Email;
use App\Services\Email\EmailBuilder;
use App\Services\Email\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmailServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmailService $emailService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emailService = app(EmailService::class);
    }

    /** @test */
    public function it_returns_email_builder_instance()
    {
        $builder = $this->emailService->builder();

        $this->assertInstanceOf(EmailBuilder::class, $builder);
    }

    /** @test */
    public function it_sends_email_via_provider()
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'success',
                'job_id' => 'job-123',
            ], 200),
        ]);

        $dto = new EmailDTO(
            from: 'sender@example.com',
            fromName: 'John Doe',
            to: ['recipient@example.com'],
            subject: 'Test Subject',
            htmlBody: '<h1>Test Body</h1>',
        );

        $email = $this->emailService->send($dto);

        $this->assertInstanceOf(Email::class, $email);
        $this->assertTrue($email->isSent());
    }

    /** @test */
    public function it_integrates_builder_and_sender()
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'success',
                'job_id' => 'job-123',
            ], 200),
        ]);

        $dto = $this->emailService
            ->builder()
            ->from('sender@example.com', 'John Doe')
            ->to('recipient@example.com')
            ->subject('Test Subject')
            ->htmlBody('<h1>Test Body</h1>')
            ->build();

        $email = $this->emailService->send($dto);

        $this->assertTrue($email->isSent());
        $this->assertDatabaseHas('emails', [
            'from' => 'sender@example.com',
            'from_name' => 'John Doe',
            'subject' => 'Test Subject',
            'status' => 'sent',
        ]);
    }

    /** @test */
    public function it_handles_provider_failure()
    {
        Http::fake([
            '*' => Http::response([
                'message' => 'Invalid API key',
            ], 401),
        ]);

        $dto = new EmailDTO(
            from: 'sender@example.com',
            fromName: 'John Doe',
            to: ['recipient@example.com'],
            subject: 'Test Subject',
            htmlBody: '<h1>Test Body</h1>',
        );

        $email = $this->emailService->send($dto);

        $this->assertTrue($email->isFailed());
        $this->assertEquals('Invalid API key', $email->error_message);
    }

    /** @test */
    public function it_sends_email_with_multiple_recipients()
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'success',
                'job_id' => 'job-123',
            ], 200),
        ]);

        $dto = $this->emailService
            ->builder()
            ->from('sender@example.com')
            ->to(['recipient1@example.com', 'recipient2@example.com', 'recipient3@example.com'])
            ->subject('Test Subject')
            ->htmlBody('<h1>Test Body</h1>')
            ->build();

        $email = $this->emailService->send($dto);

        $this->assertTrue($email->isSent());
        $this->assertCount(3, $email->to);
    }

    /** @test */
    public function it_sends_email_with_attachments()
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'success',
                'job_id' => 'job-123',
            ], 200),
        ]);

        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, 'test content');

        $dto = $this->emailService
            ->builder()
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('Test Subject')
            ->htmlBody('<h1>Test Body</h1>')
            ->attach($tempFile, 'document.txt')
            ->build();

        $email = $this->emailService->send($dto);

        $this->assertTrue($email->isSent());
        $this->assertCount(1, $email->attachments);
        $this->assertEquals('document.txt', $email->attachments[0]['name']);

        unlink($tempFile);
    }

    /** @test */
    public function multiple_emails_can_be_sent_independently()
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'success',
                'job_id' => 'job-123',
            ], 200),
        ]);

        $dto1 = new EmailDTO(
            from: 'sender@example.com',
            fromName: null,
            to: ['recipient1@example.com'],
            subject: 'First Email',
            htmlBody: '<h1>First</h1>',
        );

        $dto2 = new EmailDTO(
            from: 'sender@example.com',
            fromName: null,
            to: ['recipient2@example.com'],
            subject: 'Second Email',
            htmlBody: '<h1>Second</h1>',
        );

        $email1 = $this->emailService->send($dto1);
        $email2 = $this->emailService->send($dto2);

        $this->assertNotEquals($email1->id, $email2->id);
        $this->assertTrue($email1->isSent());
        $this->assertTrue($email2->isSent());
        $this->assertDatabaseCount('emails', 2);
    }
}
