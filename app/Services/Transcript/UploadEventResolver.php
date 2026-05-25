<?php

namespace App\Services\Transcript;

use App\Exceptions\AppException;
use App\Http\Requests\API\v1\UploadTranscriptRequest;
use App\Models\CalendarEvent;
use App\Models\Team;
use App\Models\User;
use App\Services\CalendarEventOrganizationResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

class UploadEventResolver
{
    public function __construct(
        private readonly CalendarEventOrganizationResolver $orgResolver,
    ) {
    }

    /**
     * Resolve an existing CalendarEvent or create a new synthetic one bound to the uploader.
     *
     * @throws AppException            422 on missing source / team-org mismatch / etc.
     * @throws AuthorizationException  403 on cross-org access attempts.
     */
    public function resolve(UploadTranscriptRequest $request, User $uploader): CalendarEvent
    {
        if ($request->calendarEventId() !== null) {
            return $this->loadExistingEvent($request->calendarEventId(), $uploader);
        }

        return $this->createSyntheticEvent($request, $uploader);
    }

    private function loadExistingEvent(int $eventId, User $uploader): CalendarEvent
    {
        $event = CalendarEvent::with(['source', 'creator'])->findOrFail($eventId);

        $eventOrgId = $this->orgResolver->resolveOrganizationId($event);
        if ($eventOrgId === null) {
            throw new AuthorizationException('Cannot determine organization for this event');
        }

        $uploaderOrgIds = $uploader->organizations()->pluck('organizations.id');
        if (!$uploaderOrgIds->contains($eventOrgId)) {
            throw new AuthorizationException('You are not a member of this event\'s organization');
        }

        return $event;
    }

    private function createSyntheticEvent(UploadTranscriptRequest $request, User $uploader): CalendarEvent
    {
        $source = $uploader->sources()->whereNull('deleted_at')->first();

        if (!$source) {
            throw new AppException(
                'Connect a calendar to upload transcripts',
                'NO_SOURCE',
            );
        }

        $team = Team::findOrFail($request->teamId());

        $uploaderOrgIds = $uploader->organizations()->pluck('organizations.id');
        if (!$uploaderOrgIds->contains($team->organization_id)) {
            throw new AuthorizationException('Selected team is not in your organization');
        }

        return CalendarEvent::create([
            'source_id'       => $source->id,
            'creator_user_id' => $uploader->id,
            'external_id'     => null,
            'platform'        => 'manual_upload',
            'title'           => $request->title(),
            'description'     => '',
            'url'             => 'https://wanda.local/manual/' . Str::uuid(),
            'starts_at'       => $request->startsAt(),
            'ends_at'         => $request->endsAt(),
            'required_bot'    => false,
        ]);
    }
}
