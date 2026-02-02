<?php

namespace Tests\Unit;

use App\Domain\DTO\EmailDTO;
use App\Services\Email\EmailBuilder;
use InvalidArgumentException;
use Tests\TestCase;

class EmailBuilderTest extends TestCase
{
    /** @test */
    public function it_builds_email_dto_with_all_fields()
    {
        $builder = new EmailBuilder();

        $dto = $builder
            ->from('sender@example.com', 'John Doe')
            ->to('recipient@example.com')
            ->subject('Test Subject')
            ->htmlBody('<h1>Test Body</h1>')
            ->build();

        $this->assertInstanceOf(EmailDTO::class, $dto);
        $this->assertEquals('sender@example.com', $dto->from);
        $this->assertEquals('John Doe', $dto->fromName);
        $this->assertEquals(['recipient@example.com'], $dto->to);
        $this->assertEquals('Test Subject', $dto->subject);
        $this->assertEquals('<h1>Test Body</h1>', $dto->htmlBody);
        $this->assertEmpty($dto->attachments);
    }

    /** @test */
    public function it_builds_email_dto_without_from_name()
    {
        $builder = new EmailBuilder();

        $dto = $builder
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('Test Subject')
            ->htmlBody('<h1>Test Body</h1>')
            ->build();

        $this->assertEquals('sender@example.com', $dto->from);
        $this->assertNull($dto->fromName);
    }

    /** @test */
    public function it_accepts_multiple_recipients()
    {
        $builder = new EmailBuilder();

        $dto = $builder
            ->from('sender@example.com')
            ->to(['recipient1@example.com', 'recipient2@example.com'])
            ->subject('Test Subject')
            ->htmlBody('<h1>Test Body</h1>')
            ->build();

        $this->assertCount(2, $dto->to);
        $this->assertContains('recipient1@example.com', $dto->to);
        $this->assertContains('recipient2@example.com', $dto->to);
    }

    /** @test */
    public function it_can_add_recipients_sequentially()
    {
        $builder = new EmailBuilder();

        $dto = $builder
            ->from('sender@example.com')
            ->to('recipient1@example.com')
            ->to('recipient2@example.com')
            ->subject('Test Subject')
            ->htmlBody('<h1>Test Body</h1>')
            ->build();

        $this->assertCount(2, $dto->to);
    }

    /** @test */
    public function it_removes_duplicate_recipients()
    {
        $builder = new EmailBuilder();

        $dto = $builder
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->to('recipient@example.com')
            ->subject('Test Subject')
            ->htmlBody('<h1>Test Body</h1>')
            ->build();

        $this->assertCount(1, $dto->to);
    }

    /** @test */
    public function it_throws_exception_for_invalid_from_email()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email address');

        $builder = new EmailBuilder();
        $builder->from('invalid-email');
    }

    /** @test */
    public function it_throws_exception_for_invalid_recipient_email()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email address');

        $builder = new EmailBuilder();
        $builder
            ->from('sender@example.com')
            ->to('invalid-email');
    }

    /** @test */
    public function it_throws_exception_when_from_is_missing()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('From address is required');

        $builder = new EmailBuilder();
        $builder
            ->to('recipient@example.com')
            ->subject('Test Subject')
            ->htmlBody('<h1>Test Body</h1>')
            ->build();
    }

    /** @test */
    public function it_throws_exception_when_recipients_are_missing()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one recipient is required');

        $builder = new EmailBuilder();
        $builder
            ->from('sender@example.com')
            ->subject('Test Subject')
            ->htmlBody('<h1>Test Body</h1>')
            ->build();
    }

    /** @test */
    public function it_throws_exception_when_subject_is_missing()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Subject is required');

        $builder = new EmailBuilder();
        $builder
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->htmlBody('<h1>Test Body</h1>')
            ->build();
    }

    /** @test */
    public function it_throws_exception_when_html_body_is_missing()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTML body is required');

        $builder = new EmailBuilder();
        $builder
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('Test Subject')
            ->build();
    }

    /** @test */
    public function it_adds_attachments()
    {
        $builder = new EmailBuilder();

        // Create a temporary file for testing
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, 'test content');

        $dto = $builder
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('Test Subject')
            ->htmlBody('<h1>Test Body</h1>')
            ->attach($tempFile)
            ->build();

        $this->assertCount(1, $dto->attachments);
        $this->assertEquals($tempFile, $dto->attachments[0]['path']);
        $this->assertEquals(basename($tempFile), $dto->attachments[0]['name']);

        unlink($tempFile);
    }

    /** @test */
    public function it_adds_attachments_with_custom_name()
    {
        $builder = new EmailBuilder();

        // Create a temporary file for testing
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, 'test content');

        $dto = $builder
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('Test Subject')
            ->htmlBody('<h1>Test Body</h1>')
            ->attach($tempFile, 'custom-name.txt')
            ->build();

        $this->assertEquals('custom-name.txt', $dto->attachments[0]['name']);

        unlink($tempFile);
    }

    /** @test */
    public function it_throws_exception_for_non_existent_attachment()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File not found');

        $builder = new EmailBuilder();
        $builder->attach('/non/existent/file.txt');
    }
}
