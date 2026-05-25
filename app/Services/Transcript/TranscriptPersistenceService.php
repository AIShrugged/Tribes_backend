<?php

namespace App\Services\Transcript;

use App\Domain\DTO\TranscriptEntryDTO;
use App\Models\CalendarEvent;
use App\Models\Participant;
use App\Models\TranscriptEntry;
use Illuminate\Support\Facades\DB;

/**
 * Shared write path for both Recall webhook and manual upload intake.
 *
 * The "AlreadyValidated" suffix in the method name encodes a precondition:
 * callers MUST verify entries are non-empty and within the size cap BEFORE
 * calling this — otherwise the delete-then-insert sequence will wipe the
 * existing transcript and replace it with nothing.
 */
class TranscriptPersistenceService
{
    private const INSERT_CHUNK_SIZE = 500;

    /**
     * @param  string[]  $speakers
     * @param  TranscriptEntryDTO[]  $entries
     * @return array{transcript_entries_count: int, participants_count: int}
     */
    public function persistAlreadyValidated(
        CalendarEvent $event,
        array $speakers,
        array $entries,
    ): array {
        return DB::transaction(function () use ($event, $speakers, $entries) {
            // Row lock prevents interleaved delete+insert when two uploads
            // race for the same event (double-click, webhook + manual upload).
            $event->refresh();
            DB::table('calendar_events')->where('id', $event->id)->lockForUpdate()->get();

            $event->transcriptEntries()->delete();
            $event->participants()->delete();

            $now = now();

            $participantRows = array_map(fn (string $name) => [
                'calendar_event_id' => $event->id,
                'name'              => $name,
                'created_at'        => $now,
                'updated_at'        => $now,
            ], $speakers);

            if ($participantRows !== []) {
                Participant::insert($participantRows);
            }

            $participantIdByName = Participant::where('calendar_event_id', $event->id)
                ->pluck('id', 'name')
                ->all();

            $entries = $this->fillTimingsIfMissing($event, $entries);

            $entryRows = array_map(fn (TranscriptEntryDTO $entry) => [
                'calendar_event_id' => $event->id,
                'participant_id'    => $participantIdByName[$entry->speaker] ?? null,
                'text'              => $entry->paragraph,
                'start_relative'    => $entry->startRelative,
                'end_relative'      => $entry->endRelative,
                'start_absolute'    => $entry->startAbsolute,
                'end_absolute'      => $entry->endAbsolute,
                'created_at'        => $now,
                'updated_at'        => $now,
            ], $entries);

            foreach (array_chunk($entryRows, self::INSERT_CHUNK_SIZE) as $chunk) {
                TranscriptEntry::insert($chunk);
            }

            return [
                'transcript_entries_count' => count($entries),
                'participants_count'       => count($speakers),
            ];
        });
    }

    /**
     * Two passes:
     *   1. If the first entry has no relative timing, synthesize uniform timings for all entries
     *      (spread across [starts_at, ends_at] when known, 15-second floor).
     *   2. For any entry that has relative but missing absolute timestamps, derive absolutes
     *      from event.starts_at + relative seconds. The transcript_entries table requires
     *      start_absolute/end_absolute to be non-null, so callers that supply only relatives
     *      (VTT, SRT) get this for free.
     *
     * @param  TranscriptEntryDTO[]  $entries
     * @return TranscriptEntryDTO[]
     */
    private function fillTimingsIfMissing(CalendarEvent $event, array $entries): array
    {
        if ($entries === []) {
            return $entries;
        }

        $startsAt = $event->starts_at;

        if ($entries[0]->startRelative === null) {
            $count = count($entries);
            $meetingDuration = $event->ends_at && $startsAt
                ? max(0, $startsAt->diffInSeconds($event->ends_at))
                : 0;

            $step = $meetingDuration > 0
                ? max(15.0, $meetingDuration / $count)
                : 15.0;

            $entries = array_map(function (TranscriptEntryDTO $entry, int $index) use ($step, $startsAt) {
                $startRel = $index * $step;
                $endRel   = ($index + 1) * $step;

                return $entry->withTimings(
                    startRelative: $startRel,
                    startAbsolute: $startsAt?->copy()->addSeconds((int) $startRel)->toIso8601String(),
                    endRelative: $endRel,
                    endAbsolute: $startsAt?->copy()->addSeconds((int) $endRel)->toIso8601String(),
                );
            }, $entries, array_keys($entries));
        }

        // Pass 2: derive absolutes from relatives when missing (covers VTT/SRT/timestamped-TXT).
        return array_map(function (TranscriptEntryDTO $entry) use ($startsAt) {
            if ($entry->startAbsolute !== null && $entry->endAbsolute !== null) {
                return $entry;
            }
            if ($entry->startRelative === null || $startsAt === null) {
                return $entry;
            }

            return $entry->withTimings(
                startRelative: $entry->startRelative,
                startAbsolute: $entry->startAbsolute
                    ?? $startsAt->copy()->addSeconds((int) $entry->startRelative)->toIso8601String(),
                endRelative: $entry->endRelative ?? $entry->startRelative,
                endAbsolute: $entry->endAbsolute
                    ?? $startsAt->copy()->addSeconds((int) ($entry->endRelative ?? $entry->startRelative))->toIso8601String(),
            );
        }, $entries);
    }
}
