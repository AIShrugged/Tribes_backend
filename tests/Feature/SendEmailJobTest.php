<?php

namespace Tests\Feature;

use App\Domain\DTO\EmailDTO;
use App\Jobs\SendEmailJob;
use App\Models\Email;
use App\Services\Email\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SendEmailJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_sends_email_when_job_is_executed()
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

        $job = new SendEmailJob($dto);
        $job->handle(app(EmailService::class));

        $this->assertDatabaseCount('emails', 1);
        $this->assertDatabaseHas('emails', [
            'from' => 'sender@example.com',
            'status' => 'sent',
        ]);
    }

    #[Test]
    public function it_can_be_dispatched_to_queue()
    {
        Queue::fake();

        $dto = new EmailDTO(
            from: 'sender@example.com',
            fromName: 'John Doe',
            to: ['recipient@example.com'],
            subject: 'Test Subject',
            htmlBody: '<h1>Test Body</h1>',
        );

        SendEmailJob::dispatch($dto);

        Queue::assertPushed(SendEmailJob::class, function ($job) {
            return $job->emailDto->from === 'sender@example.com'
                && $job->emailDto->subject === 'Test Subject';
        });
    }

    #[Test]
    public function it_handles_email_send_failure()
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

        $job = new SendEmailJob($dto);
        $job->handle(app(EmailService::class));

        $this->assertDatabaseHas('emails', [
            'from' => 'sender@example.com',
            'status' => 'failed',
            'error_message' => 'Invalid API key',
        ]);
    }

    #[Test]
    public function job_has_correct_retry_configuration()
    {
        $dto = new EmailDTO(
            from: 'sender@example.com',
            fromName: 'John Doe',
            to: ['recipient@example.com'],
            subject: 'Test Subject',
            htmlBody: '<h1>Test Body</h1>',
        );

        $job = new SendEmailJob($dto);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(60, $job->backoff);
    }

    #[Test]
    public function it_sends_multiple_emails_via_jobs()
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

        $job1 = new SendEmailJob($dto1);
        $job1->handle(app(EmailService::class));

        $job2 = new SendEmailJob($dto2);
        $job2->handle(app(EmailService::class));

        $this->assertDatabaseCount('emails', 2);
        $this->assertDatabaseHas('emails', ['subject' => 'First Email']);
        $this->assertDatabaseHas('emails', ['subject' => 'Second Email']);
    }

    #[Test]
    public function it_preserves_dto_data_in_serialization()
    {
        $dto = new EmailDTO(
            from: 'sender@example.com',
            fromName: 'John Doe',
            to: ['recipient1@example.com', 'recipient2@example.com'],
            subject: 'Test Subject',
            htmlBody: '<h1>Test Body</h1>',
            attachments: [
                ['path' => '/tmp/file.pdf', 'name' => 'file.pdf']
            ],
        );

        $job = new SendEmailJob($dto);

        // Simulate serialization/deserialization that happens with queues
        $serialized = serialize($job);
        $unserialized = unserialize($serialized);

        $this->assertEquals($dto->from, $unserialized->emailDto->from);
        $this->assertEquals($dto->fromName, $unserialized->emailDto->fromName);
        $this->assertEquals($dto->to, $unserialized->emailDto->to);
        $this->assertEquals($dto->subject, $unserialized->emailDto->subject);
        $this->assertEquals($dto->htmlBody, $unserialized->emailDto->htmlBody);
        $this->assertEquals($dto->attachments, $unserialized->emailDto->attachments);
    }
}
