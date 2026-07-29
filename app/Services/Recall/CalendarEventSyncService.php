<?php

namespace App\Services\Recall;

use App\Domain\DTO\EventDTO;
use App\Events\CalendarEventChanged;
use App\Models\CalendarEvent;
use App\Models\Channel;
use App\Models\Profile;
use App\Models\Source;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CalendarEventSyncService
{
    public function __construct(
        private readonly CreatorResolverService $creatorResolver,
    ) {
    }

    public function sync(Source $source, EventDTO $eventDTO, array $attendees): ?CalendarEvent
    {
        $startTime = Carbon::parse($eventDTO->startsAt)->setTimezone(config('app.timezone'));
        $endTime = Carbon::parse($eventDTO->endsAt)->setTimezone(config('app.timezone'));
        $isHostSource = !$eventDTO->creatorEmail
            || strcasecmp($source->identity, $eventDTO->creatorEmail) === 0;

        if ($startTime->lte(Carbon::now())) {
            return null;
        }

        // Deduplicate by (url, starts_at)
        $calendarEvent = CalendarEvent::where('url', $eventDTO->url)
            ->where('starts_at', $startTime)
            ->first();

        if ($calendarEvent) {
            $updates = [
                'platform'    => $eventDTO->platform,
                'title'       => $eventDTO->title,
                'description' => $eventDTO->description,
                'ends_at'     => $endTime,
            ];

            if ($isHostSource) {
                $updates['external_id'] = $eventDTO->externalId;
                if ($eventDTO->creatorEmail) {
                    $updates['creator_user_id'] = $this->creatorResolver->resolve($eventDTO->creatorEmail)
                        ?? $source->user_id;
                }
            }

            $calendarEvent->update($updates);
        } else {
            // Check if this is a moved event: same external_id in pivot but different starts_at.
            // Google Calendar keeps the same event ID when a meeting is rescheduled, so we can
            // detect a reschedule and update the existing future event instead of creating a duplicate.
            $movedEventId = DB::table('calendar_event_source')
                ->where('source_id', $source->id)
                ->where('external_id', $eventDTO->externalId)
                ->value('calendar_event_id');

            $movedEvent = $movedEventId
                ? CalendarEvent::where('id', $movedEventId)
                    ->where('url', $eventDTO->url)
                    ->where('starts_at', '>', Carbon::now())
                    ->first()
                : null;

            if ($movedEvent) {
                $updates = [
                    'platform'    => $eventDTO->platform,
                    'title'       => $eventDTO->title,
                    'description' => $eventDTO->description,
                    'starts_at'   => $startTime,
                    'ends_at'     => $endTime,
                ];

                if ($isHostSource) {
                    $updates['external_id'] = $eventDTO->externalId;
                    if ($eventDTO->creatorEmail) {
                        $updates['creator_user_id'] = $this->creatorResolver->resolve($eventDTO->creatorEmail)
                            ?? $source->user_id;
                    }
                }

                $movedEvent->update($updates);
                $calendarEvent = $movedEvent;
            } else {
                $creatorUserId = $this->creatorResolver->resolve($eventDTO->creatorEmail);
                if ($isHostSource && $eventDTO->creatorEmail) {
                    $creatorUserId ??= $source->user_id;
                }

                $calendarEvent = CalendarEvent::create([
                    'source_id'       => $source->id,
                    'creator_user_id' => $creatorUserId,
                    'external_id'     => $isHostSource ? $eventDTO->externalId : null,
                    'platform'        => $eventDTO->platform,
                    'url'             => $eventDTO->url,
                    'title'           => $eventDTO->title,
                    'description'     => $eventDTO->description,
                    'starts_at'       => $startTime,
                    'ends_at'         => $endTime,
                ]);
            }
        }

        // Attach source to event via pivot. The bot is NOT auto-required: the
        // meeting creator connects it manually from a specific organization
        // (see BotController::require). New links default to required_bot = false;
        // existing links keep whatever the creator set (required_bot + the
        // organization the bot was connected from) — we only refresh external_id,
        // which the bot scheduling relies on to survive reschedules.
        $pivotExists = $calendarEvent->sources()
            ->wherePivot('source_id', $source->id)
            ->exists();

        if ($pivotExists) {
            $calendarEvent->sources()->updateExistingPivot($source->id, [
                'external_id' => $eventDTO->externalId,
            ]);
        } else {
            $calendarEvent->sources()->attach($source->id, [
                'external_id' => $eventDTO->externalId,
                'required_bot' => false,
            ]);

            // Inherit the series' bot setting: when the organizer has enabled the
            // bot for the whole series, a newly-synced occurrence copies required_bot
            // and the connected organization from an existing sibling of the same
            // series (same source, same meeting URL). The pivot stays the single
            // source of truth — no separate "series preference" is persisted.
            if ($isHostSource && $eventDTO->url) {
                $sibling = DB::table('calendar_event_source')
                    ->join('calendar_events', 'calendar_events.id', '=', 'calendar_event_source.calendar_event_id')
                    ->where('calendar_event_source.source_id', $source->id)
                    ->where('calendar_event_source.required_bot', true)
                    ->where('calendar_events.url', $eventDTO->url)
                    ->where('calendar_events.id', '!=', $calendarEvent->id)
                    ->orderByDesc('calendar_events.starts_at')
                    ->select('calendar_event_source.organization_id')
                    ->first();

                if ($sibling) {
                    $calendarEvent->sources()->updateExistingPivot($source->id, [
                        'required_bot'    => true,
                        'organization_id' => $sibling->organization_id,
                    ]);
                }
            }
        }

        $this->syncAttendees($calendarEvent, $attendees);

        if ($isHostSource) {
            CalendarEventChanged::dispatch($calendarEvent, false);
        }

        return $calendarEvent;
    }

    public function deleteForSource(Source $source, string $externalId): int
    {
        return DB::transaction(function () use ($source, $externalId): int {
            $eventIds = DB::table('calendar_event_source')
                ->where('source_id', $source->id)
                ->where('external_id', $externalId)
                ->pluck('calendar_event_id');

            $deleted = 0;

            foreach ($eventIds as $eventId) {
                /** @var CalendarEvent|null $calendarEvent */
                $calendarEvent = CalendarEvent::query()
                    ->with('bot')
                    ->lockForUpdate()
                    ->find($eventId);

                if (!$calendarEvent) {
                    continue;
                }

                $calendarEvent->sources()->detach($source->id);

                if ($calendarEvent->sources()->exists()) {
                    if ((int) $calendarEvent->source_id === (int) $source->id) {
                        $calendarEvent->forceFill([
                            'source_id' => $calendarEvent->sources()->value('sources.id'),
                        ])->save();
                    }

                    if (!$calendarEvent->isRequiredBot() && $calendarEvent->bot?->is_active) {
                        $calendarEvent->bot->deactivate();
                    }

                    continue;
                }

                if ($calendarEvent->bot?->is_active) {
                    $calendarEvent->bot->deactivate();
                }

                $this->deleteLocalArtifacts($calendarEvent);
                $calendarEvent->delete();
                $deleted++;

                Log::info('Deleted local calendar event after Recall marked it as deleted', [
                    'source_id' => $source->id,
                    'external_id' => $externalId,
                    'calendar_event_id' => $eventId,
                ]);
            }

            return $deleted;
        });
    }

    public function deleteMissingFutureForSource(Source $source, array $presentExternalIds): int
    {
        $presentExternalIds = array_values(array_filter(array_unique($presentExternalIds)));

        return DB::transaction(function () use ($source, $presentExternalIds): int {
            $query = DB::table('calendar_event_source')
                ->join('calendar_events', 'calendar_events.id', '=', 'calendar_event_source.calendar_event_id')
                ->where('calendar_event_source.source_id', $source->id)
                ->where('calendar_events.starts_at', '>', Carbon::now())
                ->whereNotNull('calendar_event_source.external_id');

            if ($presentExternalIds !== []) {
                $query->whereNotIn('calendar_event_source.external_id', $presentExternalIds);
            }

            $externalIds = $query
                ->distinct()
                ->pluck('calendar_event_source.external_id');

            $deleted = 0;

            foreach ($externalIds as $externalId) {
                $deleted += $this->deleteForSource($source, $externalId);
            }

            return $deleted;
        });
    }

    private function deleteLocalArtifacts(CalendarEvent $calendarEvent): void
    {
        $calendarEvent->profiles()->detach();
        $calendarEvent->participants()->delete();
        $calendarEvent->transcriptEntries()->delete();
        $calendarEvent->followups()->delete();
    }

    protected function syncAttendees(CalendarEvent $calendarEvent, array $attendees): void
    {
        $gcChannelId = Channel::idFor('google_calendar');

        if (!$gcChannelId) {
            return;
        }

        $profileIds = [];

        foreach ($attendees as $attendee) {
            $profile = Profile::firstOrCreate([
                'channel_id'         => $gcChannelId,
                'channel_identifier' => $attendee->email,
            ]);
            $profileIds[] = $profile->id;
        }

        $calendarEvent->profiles()->sync($profileIds);
    }
}
