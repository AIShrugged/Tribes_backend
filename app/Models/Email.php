<?php

namespace App\Models;

use App\Enums\EmailStatus;
use Illuminate\Database\Eloquent\Model;

class Email extends Model
{
    protected $guarded = [];

    protected $casts = [
        'to' => 'array',
        'attachments' => 'array',
        'status' => EmailStatus::class,
        'sent_at' => 'datetime',
    ];

    /**
     * Check if email was sent successfully
     */
    public function isSent(): bool
    {
        return $this->status === EmailStatus::SENT;
    }

    /**
     * Check if email failed to send
     */
    public function isFailed(): bool
    {
        return $this->status === EmailStatus::FAILED;
    }

    /**
     * Check if email is pending
     */
    public function isPending(): bool
    {
        return $this->status === EmailStatus::PENDING;
    }

    /**
     * Mark email as sent
     */
    public function markAsSent(?string $providerMessageId = null): void
    {
        $this->update([
            'status' => EmailStatus::SENT,
            'provider_message_id' => $providerMessageId,
            'sent_at' => now(),
        ]);
    }

    /**
     * Mark email as failed
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => EmailStatus::FAILED,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Increment retry count
     */
    public function incrementRetryCount(): void
    {
        $this->increment('retry_count');
    }
}
