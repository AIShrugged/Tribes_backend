<?php

namespace App\Listeners\Insight;

use App\Events\Insight\InsightItemsExtracted;
use App\Services\Insight\InsightEvolutionService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class UpdateInsightProfilesListener implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    public function handle(InsightItemsExtracted $event): void
    {
        $evolution = app(InsightEvolutionService::class);

        foreach ($event->sources as $source) {
            try {
                $evolution->evolveFromSource($source);
            } catch (\Throwable $e) {
                Log::error('UpdateInsightProfilesListener: failed to evolve profile', [
                    'profile_id' => $source->profile_id,
                    'source_id'  => $source->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }
}
