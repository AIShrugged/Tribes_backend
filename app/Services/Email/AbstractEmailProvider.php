<?php

namespace App\Services\Email;

use App\Contracts\EmailProviderInterface;
use App\Domain\DTO\EmailDTO;
use App\Domain\DTO\EmailSendResultDTO;
use App\Enums\EmailStatus;
use App\Models\Email;

abstract class AbstractEmailProvider implements EmailProviderInterface
{
    /**
     * Send email via provider
     */
    public function send(EmailDTO $dto): Email
    {
        // Create Email model with pending status
        $email = Email::create([
            'from' => $dto->from,
            'from_name' => $dto->fromName,
            'to' => $dto->to,
            'subject' => $dto->subject,
            'html_body' => $dto->htmlBody,
            'attachments' => $dto->attachments,
            'status' => EmailStatus::PENDING,
            'provider' => $this->getProviderName(),
        ]);

        try {
            // Delegate actual sending to child class
            $result = $this->sendEmail($dto);

            // Update status based on result
            if ($result->success) {
                $email->markAsSent($result->messageId);
            } else {
                $email->markAsFailed($result->error ?? 'Unknown error');
            }
        } catch (\Exception $e) {
            $email->markAsFailed($e->getMessage());
        }

        return $email->fresh();
    }

    /**
     * Send email via specific provider implementation
     */
    abstract protected function sendEmail(EmailDTO $dto): EmailSendResultDTO;

    /**
     * Get provider name
     */
    abstract protected function getProviderName(): string;
}
