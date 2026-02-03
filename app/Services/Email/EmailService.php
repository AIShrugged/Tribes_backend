<?php

namespace App\Services\Email;

use App\Contracts\EmailBuilderInterface;
use App\Contracts\EmailProviderInterface;
use App\Domain\DTO\EmailDTO;
use App\Models\Email;

class EmailService
{
    public function __construct(
        private readonly EmailProviderInterface $provider,
    ) {
    }

    /**
     * Get email builder instance
     */
    public function builder(): EmailBuilderInterface
    {
        return new EmailBuilder();
    }

    /**
     * Send email via provider
     */
    public function send(EmailDTO $dto): Email
    {
        return $this->provider->send($dto);
    }
}
