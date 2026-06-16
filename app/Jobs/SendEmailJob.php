<?php

namespace App\Jobs;

use App\Domain\DTO\EmailDTO;
use App\Services\Email\EmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly EmailDTO $emailDto,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Execute the job.
     */
    public function handle(EmailService $emailService): void
    {
        try {
            $email = $emailService->send($this->emailDto);

            if ($email->isFailed()) {
                Log::warning('Email send job completed but email failed', [
                    'email_id' => $email->id,
                    'error' => $email->error_message,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception in SendEmailJob', [
                'exception' => $e->getMessage(),
                'to' => $this->emailDto->to,
            ]);

            throw $e;
        }
    }
}
