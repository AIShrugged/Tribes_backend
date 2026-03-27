<?php

namespace App\Services\Recall;

use App\Domain\DTO\EventDTO;
use App\Events\CalendarEventChanged;
use App\Models\CalendarEvent;
use App\Models\Channel;
use App\Models\Profile;
use App\Models\Source;
use Carbon\Carbon;

class CalendarEventSyncService
{
    public function __construct(
        private readonly BotSchedulingService $botSchedulingService,
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

        // Attach source to event via pivot
        $calendarEvent->sources()->syncWithoutDetaching([
            $source->id => ['external_id' => $eventDTO->externalId, 'required_bot' => true],
        ]);

        $this->syncAttendees($calendarEvent, $attendees);

        if ($calendarEvent->isRequiredBot()) {
            $this->botSchedulingService->schedule($calendarEvent);
        }

        CalendarEventChanged::dispatch($calendarEvent);

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
