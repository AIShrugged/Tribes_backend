<?php

namespace App\Jobs;

use App\Events\TranscriptParsed;
use App\Exceptions\AppException;
use App\Models\CalendarEvent;
use App\Models\Participant;
use App\Models\TranscriptEntry;
use App\Services\RecallTranscriptParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ParseTranscriptJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public CalendarEvent $calendarEvent,
        private string $url,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(RecallTranscriptParser $parser): void
    {
        // 1. Download first — Recall presigned URL имеет TTL.
        //    Если упадём после delete без успешного download, восстанавливать нечем.
        $response = Http::get($this->url);

        if (!$response->successful()) {
            throw new AppException('Unable to download transcript', 'TRANSCRIPT_DOWNLOAD_FAILED');
        }

        $payload = $response->json();

        // 2. Parse в память до того, как трогаем БД — это и валидация JSON.
        $speakers = $parser->getSpeakers($payload);
        $transcriptEntries = $parser->parse($payload);

        // 3. Транзакция: delete старых + create новых атомарно.
        DB::transaction(function () use ($speakers, $transcriptEntries) {
            $this->calendarEvent->transcriptEntries()->delete();
            $this->calendarEvent->participants()->delete();

            $participants = [];
            foreach ($speakers as $speaker) {
                $participant = Participant::create([
                    'calendar_event_id' => $this->calendarEvent->id,
                    'name'              => $speaker,
                ]);
                $participants[$speaker] = $participant->id;
            }

            foreach ($transcriptEntries as $entry) {
                TranscriptEntry::create([
                    'calendar_event_id' => $this->calendarEvent->id,
                    'participant_id'    => $participants[$entry->speaker],
                    'text'              => $entry->paragraph,
                    'start_relative'    => $entry->startRelative,
                    'end_relative'      => $entry->endRelative,
                    'start_absolute'    => $entry->startAbsolute,
                    'end_absolute'      => $entry->endAbsolute,
                ]);
            }
        });

        // 4. Event только после commit.
        TranscriptParsed::dispatch($this->calendarEvent);
    }
}
