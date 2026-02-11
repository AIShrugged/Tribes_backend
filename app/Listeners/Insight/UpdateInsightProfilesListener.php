<?php

namespace App\Listeners\Insight;

use App\Events\Insight\InsightItemsExtracted;
use App\Services\Insight\InsightEvolutionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class UpdateInsightProfilesListener implements ShouldQueue
{
    public string $queue = 'default';

    public function handle(InsightItemsExtracted $event): void
    {
        $evolution = app(InsightEvolutionService::class);

        foreach ($event->sources as $source) {
            try {
                $evolution->evolveFromSource($source->email, $source);
            } catch (\Throwable $e) {
                Log::error('UpdateInsightProfilesListener: failed to evolve profile', [
                    'email'     => $source->email,
                    'source_id' => $source->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }
}
