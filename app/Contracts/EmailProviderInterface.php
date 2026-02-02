<?php

namespace App\Contracts;

use App\Domain\DTO\EmailDTO;
use App\Models\Email;

interface EmailProviderInterface
{
    /**
     * Send email via provider
     *
     * @return Email Email model with updated status
     */
    public function send(EmailDTO $dto): Email;
}
