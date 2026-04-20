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

        if ($startTime->lte(Carbon::now())) {
            return null;
        }

        // Deduplicate by (url, starts_at)
        $calendarEvent = CalendarEvent::where('url', $eventDTO->url)
            ->where('starts_at', $startTime)
            ->first();

        if ($calendarEvent) {
            $calendarEvent->update([
                'platform'    => $eventDTO->platform,
                'title'       => $eventDTO->title,
                'description' => $eventDTO->description,
                'ends_at'     => $endTime,
            ]);
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
                $movedEvent->update([
                    'platform'    => $eventDTO->platform,
                    'title'       => $eventDTO->title,
                    'description' => $eventDTO->description,
                    'starts_at'   => $startTime,
                    'ends_at'     => $endTime,
                ]);
                $calendarEvent = $movedEvent;
            } else {
                $creatorUserId = $this->creatorResolver->resolve($eventDTO->creatorEmail);

                $calendarEvent = CalendarEvent::create([
                    'source_id'       => $source->id,
                    'creator_user_id' => $creatorUserId,
                    'external_id'     => $eventDTO->externalId,
                    'platform'        => $eventDTO->platform,
                    'url'             => $eventDTO->url,
                    'title'           => $eventDTO->title,
                    'description'     => $eventDTO->description,
                    'starts_at'       => $startTime,
                    'ends_at'         => $endTime,
                ]);
            }
        }

        // Attach source to event via pivot
        $calendarEvent->sources()->syncWithoutDetaching([
            $source->id => ['external_id' => $eventDTO->externalId, 'required_bot' => true],
        ]);

        // syncWithoutDetaching keeps existing pivot rows as-is, so make sure
        // an already-linked meeting is actually marked as requiring the bot.
        $calendarEvent->sources()->updateExistingPivot($source->id, [
            'external_id' => $eventDTO->externalId,
            'required_bot' => true,
        ]);

        $this->syncAttendees($calendarEvent, $attendees);

        CalendarEventChanged::dispatch($calendarEvent, false);

        return $calendarEvent;
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
