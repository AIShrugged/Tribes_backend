<?php

namespace App\Jobs\Demo;

use App\Models\CalendarEvent;
use App\Models\DemoGeneration;
use App\Services\Insight\InsightRelationshipService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessDemoRelationshipsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300;

    public function __construct(private readonly int $generationId)
    {
    }

    public function handle(InsightRelationshipService $relationshipService): void
    {
        $generation = DemoGeneration::findOrFail($this->generationId);
        $generation->updateProgress('Анализ отношений в командах...', 80);

        try {
            $data = $generation->data ?? [];

            foreach ($data['teams'] ?? [] as $teamData) {
                $profileIds = $teamData['profile_ids'] ?? [];

                if (count($profileIds) < 2) {
                    continue;
                }

                // Process relationships from each event in this team
                foreach ($teamData['events'] ?? [] as $eventData) {
                    $event = CalendarEvent::find($eventData['event_id']);
                    if (!$event) {
                        continue;
                    }

                    try {
                        $relationshipService->processFromEvent($event, $profileIds);
                    } catch (\Throwable $e) {
                        Log::warning('ProcessDemoRelationshipsJob: relationship processing failed for event', [
                            'event_id' => $eventData['event_id'],
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }
            }

            Log::info('ProcessDemoRelationshipsJob: relationships processed', [
                'generation_id' => $this->generationId,
            ]);

            FinalizeDemoJob::dispatch($this->generationId);
        } catch (\Throwable $e) {
            Log::error('ProcessDemoRelationshipsJob: failed', ['error' => $e->getMessage()]);
            $generation->markFailed('Ошибка анализа отношений: ' . $e->getMessage());
        }
    }
}
