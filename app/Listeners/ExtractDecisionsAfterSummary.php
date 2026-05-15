<?php

namespace App\Listeners;

use App\Events\MeetingSummaryGenerated;
use App\Services\Decisions\ExtractDecisionsService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExtractDecisionsAfterSummary implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        private readonly ExtractDecisionsService $extractor,
    ) {}

    public function handle(MeetingSummaryGenerated $event): void
    {
        try {
            $count = $this->extractor->extract($event->summary);

            if ($count > 0) {
                Log::info('ExtractDecisionsAfterSummary: extracted decisions', [
                    'summary_id' => $event->summary->id,
                    'count'      => $count,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('ExtractDecisionsAfterSummary: failed', [
                'summary_id' => $event->summary->id,
                'error'      => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
