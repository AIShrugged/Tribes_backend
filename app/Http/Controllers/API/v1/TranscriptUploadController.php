<?php

namespace App\Http\Controllers\API\v1;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\UploadTranscriptRequest;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use App\Models\ExtractionPlan;
use App\Models\Team;
use App\Models\TranscriptUpload;
use App\Models\User;
use App\Services\CalendarEventOrganizationResolver;
use App\Services\Transcript\Exceptions\TooManyEntriesException;
use App\Services\Transcript\Exceptions\TranscriptParseException;
use App\Services\Transcript\Exceptions\UnsupportedFormatException;
use App\Services\Transcript\TranscriptUploadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TranscriptUploadController extends Controller
{
    public function __construct(
        private readonly TranscriptUploadService $service,
        private readonly CalendarEventOrganizationResolver $orgResolver,
    ) {
    }

    public function upload(UploadTranscriptRequest $request): ApiResponse
    {
        $user = $request->user();

        // Best-effort upload-log row. Created BEFORE processing so failures are
        // visible too (D2). All record writes go through safeMark and can NEVER
        // turn a working 201/4xx into a 500 or roll back persisted entries.
        $record = null;
        $this->safeMark(function () use (&$record, $request, $user) {
            $record = TranscriptUpload::create([
                'user_id'           => $user->id,
                'organization_id'   => null, // resolved from the event on success
                'original_filename' => Str::limit(
                    preg_replace('/[\x00-\x1F]/', '', $request->file('file')?->getClientOriginalName() ?? 'transcript'),
                    255,
                    '',
                ),
                'status'            => 'pending',
            ]);
        });

        // Resolved separately from parsing so we know the (already-persisted) event id
        // even when parsing later throws — populates calendar_event_id on the failed row.
        $event = null;

        try {
            $event  = $this->service->resolveEvent($request, $user);

            // EVERY upload through this controller is a manual admin upload → always moderate,
            // including "attach transcript to an existing (Recall) meeting" (the event keeps its
            // recall platform, so we key moderation on the plan's existence, NOT on platform).
            // Recall ingestion never reaches this controller (it goes via ParseTranscriptJob).
            // The collecting plan AND the upload row's calendar_event_id must be committed BEFORE
            // parseAndPersist dispatches TranscriptParsed — under a sync queue the
            // ShouldQueueAfterCommit producers run immediately and must find both to stage/flip.
            $gated = true;

            if ($gated) {
                $this->safeMark(fn () => $record?->update([
                    'status'            => 'processing',
                    'calendar_event_id' => $event->id,
                    'organization_id'   => $this->resolveUploadOrg($event, $request),
                ]));
                $this->createExtractionPlan($event, $request, $user);
            }

            $result = $this->service->parseAndPersist($event, $request, $user);

            // Non-gated: finalize 'done' as before. Gated: the barrier coordinator owns the status
            // (processing → pending_review → done/failed); only stamp the counts here.
            $this->safeMark(fn () => $record?->update($gated
                ? [
                    'transcript_entries_count' => $result['transcript_entries_count'],
                    'participants_count'       => $result['participants_count'],
                ]
                : [
                    'status'                   => 'done',
                    'calendar_event_id'        => $event->id,
                    'organization_id'          => $this->resolveUploadOrg($event, $request),
                    'transcript_entries_count' => $result['transcript_entries_count'],
                    'participants_count'       => $result['participants_count'],
                ]));

            return ApiResponse::success(
                message: 'Transcript uploaded',
                data: [
                    'upload_id'                => $record?->id,
                    'moderation'               => $gated,
                    'calendar_event_id'        => $result['calendar_event']->id,
                    'transcript_entries_count' => $result['transcript_entries_count'],
                    'participants_count'       => $result['participants_count'],
                ],
                status: 201,
            );
        } catch (AuthorizationException $e) {
            $this->markFailed($record, $event, 'You are not allowed to upload to this event');

            return ApiResponse::error(
                message: $e->getMessage() ?: 'Forbidden',
                status: 403,
            );
        } catch (AppException $e) {
            $this->markFailed($record, $event, $this->appErrorMessage($e));

            return ApiResponse::error(
                message: $e->getMessage(),
                data: ['error_code' => $e->getErrorCode() ?: 'APP_ERROR'],
                status: 422,
            );
        } catch (TooManyEntriesException $e) {
            $this->markFailed($record, $event, 'Transcript exceeds the entry limit');

            return ApiResponse::error(
                message: 'Transcript exceeds the entry limit',
                data: ['error_code' => 'TOO_MANY_ENTRIES', 'count' => $e->count, 'limit' => $e->limit],
                status: 422,
            );
        } catch (UnsupportedFormatException $e) {
            // Signature detector + LLM fallback both gave up on the file.
            $logId = (string) Str::uuid();
            Log::warning('TranscriptUpload: format unrecognized', [
                'log_id'      => $logId,
                'uploader_id' => $user?->id,
                'exception'   => $e->getMessage(),
            ]);
            $this->markFailed($record, $event, 'Transcript format not recognized');

            return ApiResponse::error(
                message: 'Transcript format not recognized',
                data: ['error_code' => 'TRANSCRIPT_FORMAT_UNRECOGNIZED', 'log_id' => $logId],
                status: 422,
            );
        } catch (TranscriptParseException $e) {
            // Don't leak parser internals (file paths, JSON byte offsets, etc).
            $logId = (string) Str::uuid();
            Log::warning('TranscriptUpload: parse failed', [
                'log_id'      => $logId,
                'uploader_id' => $user?->id,
                'exception'   => $e->getMessage(),
            ]);
            $this->markFailed($record, $event, 'Could not parse transcript file');

            return ApiResponse::error(
                message: 'Could not parse transcript file',
                data: ['error_code' => 'TRANSCRIPT_PARSE_FAILED', 'log_id' => $logId],
                status: 422,
            );
        }
    }

    /**
     * Org for the done upload-log row. Prefer the event's authoritative org; for a
     * synthetic event whose source has no org (multi-org uploader), fall back to the
     * selected team's org (already validated as the uploader's in UploadEventResolver),
     * so a SUCCESSFUL upload never silently drops out of the org-wide log with org=null.
     */
    private function resolveUploadOrg(CalendarEvent $event, UploadTranscriptRequest $request): ?int
    {
        $orgId = $this->orgResolver->resolveOrganizationId($event);
        if ($orgId !== null) {
            return $orgId;
        }

        $teamId = $request->teamId();

        return $teamId ? Team::whereKey($teamId)->value('organization_id') : null;
    }

    /** Mark the upload-log row failed with a sanitized, fixed reason + (if known) the event id. */
    private function markFailed(?TranscriptUpload $record, ?CalendarEvent $event, string $message): void
    {
        $this->safeMark(fn () => $record?->update([
            'status'            => 'failed',
            'error_message'     => $message,
            'calendar_event_id' => $event?->id,
        ]));
    }

    /** Map an AppException to a fixed allow-listed reason — never persist raw getMessage(). */
    private function appErrorMessage(AppException $e): string
    {
        return match ($e->getErrorCode()) {
            'NO_SOURCE'             => 'Connect a calendar to upload transcripts',
            'CONTENT_NOT_RELEVANT'  => 'Transcript does not appear to be related to this organization\'s work',
            default                 => 'Upload could not be processed',
        };
    }

    /**
     * Eagerly create the collecting moderation plan (single writer — no firstOrCreate race). The
     * issues section is expected only when the issue-extraction branch will actually dispatch
     * (CalendarEventOrganizationResolver::resolve != null), mirroring GenerateFollowup's own gate so
     * the barrier never waits for a section that never arrives. Best-effort like the other log writes.
     */
    private function createExtractionPlan(CalendarEvent $event, UploadTranscriptRequest $request, User $user): void
    {
        $this->safeMark(function () use ($event, $request, $user) {
            $expected = ['decisions'];
            if ($this->orgResolver->resolve($event) !== null) {
                $expected[] = 'issues';
            }

            ExtractionPlan::updateOrCreate(
                ['sourceable_type' => CalendarEvent::class, 'sourceable_id' => $event->id],
                [
                    'status'            => ExtractionPlan::STATUS_COLLECTING,
                    'team_id'           => $this->orgResolver->resolveDefaultTeamId($event),
                    'organization_id'   => $this->resolveUploadOrg($event, $request),
                    'user_id'           => $event->creator_user_id ?? $user->id,
                    'expected_sections' => $expected,
                    'section_status'    => [],
                    'plan'              => ['issues' => null, 'decisions' => null, 'review' => null],
                ],
            );
        });
    }

    /** Run a best-effort upload-log write; a logging failure must never break the upload. */
    private function safeMark(\Closure $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('TranscriptUpload: log write failed', ['error' => $e->getMessage()]);
        }
    }
}
