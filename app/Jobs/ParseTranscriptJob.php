<?php

namespace App\Jobs;

use App\Exceptions\AppException;
use App\Models\Participant;
use App\Models\TranscriptEntry;
use App\Services\RecallTranscriptParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class ParseTranscriptJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private int $calendarEventId,
        private string $url,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(RecallTranscriptParser $parser): void
    {
        $response = Http::get($this->url);

        if (!$response->successful()) {
            throw new AppException('Unable to download transcript', 'TRANSCRIPT_DOWNLOAD_FAILED');
        }

        $speakers = $parser->getSpeakers($response->json());

        $participants = [];
        foreach ($speakers as $speaker) {
            $participant = Participant::create(['calendar_event_id' => $this->calendarEventId, 'name' => $speaker]);

            $participants[$speaker] = $participant->id;
        }

        $transcriptEntries = $parser->parse($response->json());

        foreach ($transcriptEntries as $entry) {
            TranscriptEntry::create([
                'calendar_event_id' => $this->calendarEventId,
                'participant_id'    => $participants[$entry->speaker],
                'text'              => $entry->paragraph,
                'start_relative'    => $entry->startRelative,
                'end_relative'      => $entry->endRelative,
                'start_absolute'    => $entry->startAbsolute,
                'end_absolute'      => $entry->endAbsolute,
            ]);
        }
    }
}
