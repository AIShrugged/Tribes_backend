<?php

namespace App\Jobs;

use App\Events\TranscriptParsed;
use App\Exceptions\AppException;
use App\Models\CalendarEvent;
use App\Services\RecallTranscriptParser;
use App\Services\Transcript\TranscriptPersistenceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ParseTranscriptJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CalendarEvent $calendarEvent,
        private string $url,
    ) {
        $this->onQueue('heavy');
    }

    public function handle(
        RecallTranscriptParser $parser,
        TranscriptPersistenceService $persistence,
    ): void {
        // 1. Download first — Recall presigned URL has TTL; falling between delete and re-insert
        //    would leave us with no way to recover.
        $response = Http::get($this->url);

        if (!$response->successful()) {
            throw new AppException('Unable to download transcript', 'TRANSCRIPT_DOWNLOAD_FAILED');
        }

        $payload = $response->json();

        $speakers = $parser->getSpeakers($payload);
        $entries  = $parser->parse($payload);

        if ($entries === []) {
            Log::warning('ParseTranscriptJob: Recall transcript parsed to zero entries — skipping persist', [
                'calendar_event_id' => $this->calendarEvent->id,
            ]);
            return;
        }

        $persistence->persistAlreadyValidated($this->calendarEvent, $speakers, $entries);

        TranscriptParsed::dispatch($this->calendarEvent);
    }
}
