<?php

namespace App\Services\Transcript;

use App\Events\TranscriptParsed;
use App\Http\Requests\API\v1\UploadTranscriptRequest;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\Transcript\Exceptions\TooManyEntriesException;
use App\Services\Transcript\Exceptions\TranscriptParseException;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates manual transcript upload — alternate intake path that lands at the
 * same TranscriptParsed event boundary as the Recall webhook flow.
 *
 * Format detection cascade lives in {@see TranscriptFormatResolver} (signature
 * detectors → LLM fallback). This service stays linear:
 *   resolve event → normalize → resolve format → parse → persist → dispatch.
 *
 * Behaviour on re-upload to an existing event is intentional "сам дурак":
 *   - transcript_entries / participants are replaced atomically (idempotent)
 *   - MeetingSummary / MeetingReview / Insights are idempotent via their own services
 *   - Followups WILL be created again and a second Telegram message WILL go out
 *
 * This is acceptable signal during MVP test phase per the plan.
 * See docs/plans/2026-05-25-feat-manual-transcript-upload-plan.md (decisions D11, O5).
 */
class TranscriptUploadService
{
    private const ENTRY_LIMIT = 10_000;
    private const MIN_YIELD_RATIO = 0.6;

    public function __construct(
        private readonly UploadEventResolver $eventResolver,
        private readonly TranscriptContentNormalizer $normalizer,
        private readonly TranscriptFormatResolver $formatResolver,
        private readonly TranscriptPatternDetector $patternDetector,
        private readonly TranscriptPersistenceService $persistence,
    ) {
    }

    /**
     * @return array{calendar_event: CalendarEvent, transcript_entries_count: int, participants_count: int}
     */
    public function handle(UploadTranscriptRequest $request, User $uploader): array
    {
        $event   = $this->eventResolver->resolve($request, $uploader);
        $content = $this->normalizer->normalize($request->file('file')->get());

        $resolved = $this->formatResolver->resolve($content);
        $parsed   = $resolved->parser->parse($content);

        // Generic parser reports a yield ratio; if it's too low, the spec doesn't fit
        // the file. Invalidate the cached spec so the next upload won't get the same
        // broken spec back. Plan D-FALLBACK-FAIL.
        $isGeneric = $resolved->format === 'generic';
        $yieldRatio = $parsed['_meta']['yield_ratio'] ?? 1.0;

        if ($isGeneric && $yieldRatio < self::MIN_YIELD_RATIO) {
            $this->patternDetector->invalidate($content);
            throw new TranscriptParseException(
                'LLM-detected spec parsed only ' . round($yieldRatio * 100) . '% of lines',
            );
        }

        if ($parsed['entries'] === []) {
            if ($isGeneric) {
                $this->patternDetector->invalidate($content);
            }
            throw new TranscriptParseException('No transcript entries found in file');
        }

        if (count($parsed['entries']) > self::ENTRY_LIMIT) {
            throw new TooManyEntriesException(count($parsed['entries']), self::ENTRY_LIMIT);
        }

        $counts = $this->persistence->persistAlreadyValidated(
            $event,
            $parsed['speakers'],
            $parsed['entries'],
        );

        Log::info('transcript_upload.fanout', [
            'event_id'      => $event->id,
            'uploader_id'   => $uploader->id,
            'format'        => $resolved->format,
            'entries_count' => $counts['transcript_entries_count'],
            'participants'  => $counts['participants_count'],
            'yield_ratio'   => $isGeneric ? round($yieldRatio, 2) : null,
        ]);

        TranscriptParsed::dispatch($event);

        return [
            'calendar_event'           => $event,
            'transcript_entries_count' => $counts['transcript_entries_count'],
            'participants_count'       => $counts['participants_count'],
        ];
    }
}
