<?php

namespace App\Jobs;

use App\Models\OrganizationOnboardingDraft;
use App\Services\Onboarding\CombinedOnboardingGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateOrganizationStructureJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;
    public int $tries   = 1;

    public function __construct(
        private readonly int $draftId,
    ) {
        $this->onQueue('heavy');
    }

    public function handle(CombinedOnboardingGenerationService $service): void
    {
        $draft = OrganizationOnboardingDraft::findOrFail($this->draftId);
        $draft->update(['status' => 'processing']);

        try {
            $result = $service->generate(
                org:     $draft->organization,
                payload: $draft->payload ?? [],
                userId:  $draft->user_id,
            );

            $draft->update(['status' => 'completed', 'result' => $result]);
        } catch (\Throwable $e) {
            Log::error('Onboarding generation failed', [
                'draft_id' => $this->draftId,
                'error'    => $e->getMessage(),
            ]);

            $draft->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }
    }
}
