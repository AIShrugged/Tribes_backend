<?php

namespace App\Contracts;

use App\Domain\DTO\EmailDTO;

interface EmailBuilderInterface
{
    /**
     * Set the sender email address
     */
    public function from(string $email, ?string $name = null): self;

    /**
     * Add recipient(s)
     *
     * @param string|array<string> $email
     */
    public function to(string|array $email): self;

    /**
     * Set email subject
     */
    public function subject(string $subject): self;

    /**
     * Set HTML body
     */
    public function htmlBody(string $html): self;

    /**
     * Attach a file
     */
    public function attach(string $path, ?string $name = null): self;

    /**
     * Build and return EmailDTO
     */
    public function build(): EmailDTO;
}
