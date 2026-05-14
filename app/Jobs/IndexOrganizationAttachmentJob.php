<?php

namespace App\Jobs;

use App\Models\IssueAttachment;
use App\Services\Onboarding\OrganizationContextIndexerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IndexOrganizationAttachmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 2;

    public function __construct(private readonly int $attachmentId) {}

    public function handle(OrganizationContextIndexerService $service): void
    {
        $attachment = IssueAttachment::findOrFail($this->attachmentId);

        try {
            $service->indexAttachment($attachment);
        } catch (\Throwable $e) {
            Log::error('Organization attachment indexing failed', [
                'attachment_id' => $this->attachmentId,
                'error'         => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
