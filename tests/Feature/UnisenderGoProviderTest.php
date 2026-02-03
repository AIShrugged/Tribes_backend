<?php

namespace Tests\Feature;

use App\Domain\DTO\EmailDTO;
use App\Enums\EmailStatus;
use App\Models\Email;
use App\Services\Email\Providers\UnisenderGoProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UnisenderGoProviderTest extends TestCase
{
    use RefreshDatabase;

    private UnisenderGoProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new UnisenderGoProvider(
            apiKey: 'test-api-key',
            apiUrl: 'https://api.unisender.com',
        );
    }

    /** @test */
    public function it_sends_email_successfully()
    {
        Http::fake([
            'https://api.unisender.com/ru/transactional/api/v1/email/send.json' => Http::response([
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

        $email = $this->provider->send($dto);

        $this->assertInstanceOf(Email::class, $email);
        $this->assertTrue($email->isSent());
        $this->assertEquals('job-123', $email->provider_message_id);
        $this->assertNotNull($email->sent_at);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.unisender.com/ru/transactional/api/v1/email/send.json'
                && $request->hasHeader('X-API-KEY', 'test-api-key')
                && $request['message']['recipients'][0]['email'] === 'recipient@example.com'
                && $request['message']['subject'] === 'Test Subject'
                && $request['message']['from_email'] === 'sender@example.com'
                && $request['message']['from_name'] === 'John Doe';
        });
    }

    /** @test */
    public function it_sends_email_without_from_name()
    {
        Http::fake([
            'https://api.unisender.com/ru/transactional/api/v1/email/send.json' => Http::response([
                'status' => 'success',
                'job_id' => 'job-123',
            ], 200),
        ]);

        $dto = new EmailDTO(
            from: 'sender@example.com',
            fromName: null,
            to: ['recipient@example.com'],
            subject: 'Test Subject',
            htmlBody: '<h1>Test Body</h1>',
        );

        $email = $this->provider->send($dto);

        $this->assertTrue($email->isSent());

        Http::assertSent(function ($request) {
            return $request['message']['from_email'] === 'sender@example.com'
                && !isset($request['message']['from_name']);
        });
    }

    /** @test */
    public function it_sends_email_to_multiple_recipients()
    {
        Http::fake([
            'https://api.unisender.com/ru/transactional/api/v1/email/send.json' => Http::response([
                'status' => 'success',
                'job_id' => 'job-123',
            ], 200),
        ]);

        $dto = new EmailDTO(
            from: 'sender@example.com',
            fromName: 'John Doe',
            to: ['recipient1@example.com', 'recipient2@example.com'],
            subject: 'Test Subject',
            htmlBody: '<h1>Test Body</h1>',
        );

        $email = $this->provider->send($dto);

        $this->assertTrue($email->isSent());

        Http::assertSent(function ($request) {
            return count($request['message']['recipients']) === 2
                && $request['message']['recipients'][0]['email'] === 'recipient1@example.com'
                && $request['message']['recipients'][1]['email'] === 'recipient2@example.com';
        });
    }

    /** @test */
    public function it_sends_email_with_attachments()
    {
        Http::fake([
            'https://api.unisender.com/ru/transactional/api/v1/email/send.json' => Http::response([
                'status' => 'success',
                'job_id' => 'job-123',
            ], 200),
        ]);

        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, 'test content');

        $dto = new EmailDTO(
            from: 'sender@example.com',
            fromName: 'John Doe',
            to: ['recipient@example.com'],
            subject: 'Test Subject',
            htmlBody: '<h1>Test Body</h1>',
            attachments: [
                ['path' => $tempFile, 'name' => 'test.txt']
            ],
        );

        $email = $this->provider->send($dto);

        $this->assertTrue($email->isSent());

        Http::assertSent(function ($request) {
            return isset($request['message']['attachments'])
                && count($request['message']['attachments']) === 1
                && $request['message']['attachments'][0]['name'] === 'test.txt';
        });

        unlink($tempFile);
    }

    /** @test */
    public function it_marks_email_as_failed_on_api_error()
    {
        Http::fake([
            'https://api.unisender.com/ru/transactional/api/v1/email/send.json' => Http::response([
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

        $email = $this->provider->send($dto);

        $this->assertTrue($email->isFailed());
        $this->assertEquals('Invalid API key', $email->error_message);
        $this->assertNull($email->sent_at);
    }

    /** @test */
    public function it_marks_email_as_failed_on_network_error()
    {
        Http::fake([
            'https://api.unisender.com/ru/transactional/api/v1/email/send.json' => function () {
                throw new \Exception('Connection timeout');
            },
        ]);

        $dto = new EmailDTO(
            from: 'sender@example.com',
            fromName: 'John Doe',
            to: ['recipient@example.com'],
            subject: 'Test Subject',
            htmlBody: '<h1>Test Body</h1>',
        );

        $email = $this->provider->send($dto);

        $this->assertTrue($email->isFailed());
        $this->assertStringContainsString('Connection timeout', $email->error_message);
    }

    /** @test */
    public function it_creates_email_model_before_sending()
    {
        Http::fake([
            'https://api.unisender.com/ru/transactional/api/v1/email/send.json' => Http::response([
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

        $this->assertDatabaseCount('emails', 0);

        $email = $this->provider->send($dto);

        $this->assertDatabaseCount('emails', 1);
        $this->assertDatabaseHas('emails', [
            'id' => $email->id,
            'from' => 'sender@example.com',
            'from_name' => 'John Doe',
            'subject' => 'Test Subject',
            'provider' => 'unisender_go',
        ]);
    }

    /** @test */
    public function it_stores_correct_payload_in_database()
    {
        Http::fake([
            'https://api.unisender.com/ru/transactional/api/v1/email/send.json' => Http::response([
                'status' => 'success',
                'job_id' => 'job-123',
            ], 200),
        ]);

        $dto = new EmailDTO(
            from: 'sender@example.com',
            fromName: 'John Doe',
            to: ['recipient1@example.com', 'recipient2@example.com'],
            subject: 'Test Subject',
            htmlBody: '<h1>Test Body</h1>',
            attachments: [],
        );

        $email = $this->provider->send($dto);

        $savedEmail = Email::find($email->id);
        $this->assertCount(2, $savedEmail->to);
        $this->assertEquals('<h1>Test Body</h1>', $savedEmail->html_body);
    }
}
