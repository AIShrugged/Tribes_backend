<?php

namespace App\Services\Email\Providers;

use App\Domain\DTO\EmailDTO;
use App\Domain\DTO\EmailSendResultDTO;
use App\Services\Email\AbstractEmailProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UnisenderGoProvider extends AbstractEmailProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiUrl,
    ) {
    }

    protected function sendEmail(EmailDTO $dto): EmailSendResultDTO
    {
        try {
            $payload = $this->buildPayload($dto);

            $response = Http::withHeaders([
                'X-API-KEY' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->apiUrl}/ru/transactional/api/v1/email/send.json", $payload);

            if ($response->successful()) {
                $data = $response->json();
                $messageId = $data['job_id'] ?? null;

                Log::info('Email sent via Unisender GO', [
                    'message_id' => $messageId,
                    'to' => $dto->to,
                ]);

                return new EmailSendResultDTO(
                    success: true,
                    messageId: $messageId,
                );
            }

            $errorMessage = $response->json('message') ?? $response->body();

            Log::error('Failed to send email via Unisender GO', [
                'status' => $response->status(),
                'error' => $errorMessage,
                'to' => $dto->to,
            ]);

            return new EmailSendResultDTO(
                success: false,
                error: $errorMessage,
            );
        } catch (\Exception $e) {
            Log::error('Exception while sending email via Unisender GO', [
                'exception' => $e->getMessage(),
                'to' => $dto->to,
            ]);

            return new EmailSendResultDTO(
                success: false,
                error: $e->getMessage(),
            );
        }
    }

    protected function getProviderName(): string
    {
        return 'unisender_go';
    }

    private function buildPayload(EmailDTO $dto): array
    {
        $message = [
            'recipients' => array_map(fn($to) => ['email' => $to], $dto->to),
            'subject' => $dto->subject,
            'body' => [
                'html' => $dto->htmlBody,
            ],
            'from_email' => $dto->from,
        ];

        // Add from_name if present
        if ($dto->fromName) {
            $message['from_name'] = $dto->fromName;
        }
        
        $message['options']['custom_backend_id'] = config('email.providers.unisender_go.backend_id');
        $message['headers']['X-UNISENDER-GO-Global-Language'] = 'en'; //TODO: should be defiend by user settings
        $payload = ['message' => $message];

        // Add attachments if present
        if (!empty($dto->attachments)) {
            $payload['message']['attachments'] = array_map(function ($attachment) {
                return [
                    'type' => mime_content_type($attachment['path']),
                    'name' => $attachment['name'],
                    'content' => base64_encode(file_get_contents($attachment['path'])),
                ];
            }, $dto->attachments);
        }

        return $payload;
    }
}
