<?php

namespace App\Services\Email;

use App\Contracts\EmailBuilderInterface;
use App\Domain\DTO\EmailDTO;
use InvalidArgumentException;

class EmailBuilder implements EmailBuilderInterface
{
    private string $from;
    private ?string $fromName = null;
    private array $to = [];
    private string $subject = '';
    private string $htmlBody = '';
    private array $attachments = [];

    public function from(string $email, ?string $name = null): self
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email address: {$email}");
        }

        $this->from = $email;
        $this->fromName = $name;

        return $this;
    }

    public function to(string|array $email): self
    {
        $emails = is_array($email) ? $email : [$email];

        foreach ($emails as $e) {
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException("Invalid email address: {$e}");
            }
            $this->to[] = $e;
        }

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function htmlBody(string $html): self
    {
        $this->htmlBody = $html;
        return $this;
    }

    public function attach(string $path, ?string $name = null): self
    {
        if (!file_exists($path)) {
            throw new InvalidArgumentException("File not found: {$path}");
        }

        $this->attachments[] = [
            'path' => $path,
            'name' => $name ?? basename($path),
        ];

        return $this;
    }

    public function build(): EmailDTO
    {
        if (!isset($this->from)) {
            throw new InvalidArgumentException('From address is required');
        }

        if (empty($this->to)) {
            throw new InvalidArgumentException('At least one recipient is required');
        }

        if (empty($this->subject)) {
            throw new InvalidArgumentException('Subject is required');
        }

        if (empty($this->htmlBody)) {
            throw new InvalidArgumentException('HTML body is required');
        }

        return new EmailDTO(
            from: $this->from,
            fromName: $this->fromName,
            to: array_unique($this->to),
            subject: $this->subject,
            htmlBody: $this->htmlBody,
            attachments: $this->attachments,
        );
    }
}
