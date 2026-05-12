<?php

namespace App\Jobs;

use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\Team;
use App\Models\User;
use App\Services\Issue\EpicAuthorNotifier;
use App\Services\Issue\EpicExtractionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExtractEpicsFromTranscriptJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    // No retries: a retry would re-fire Telegram notifications and risk duplicate epics.
    public int $tries = 1;

    // Prevent duplicate dispatches (e.g. if VerifyMeetingArtifactsJob retries) within an hour.
    public int $uniqueFor = 3600;

    public function __construct(
        public CalendarEvent $event,
        public Team $team,
        public User $user,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->event->id;
    }

    public function handle(EpicExtractionService $service, EpicAuthorNotifier $notifier): void
    {
        $meetingIssues = Issue::query()
            ->forMeeting($this->event->id)
            ->where('type', '!=', Issue::TYPE_EPIC)
            ->whereDoesntHave('issueType', fn ($q) => $q->where('base_type', 'epic'))
            ->get(['id', 'name', 'description', 'type', 'epic_id']);

        $result = $service->extract($this->event, $this->team, $this->user, $meetingIssues);

        $notifier->notifyBatch($result, $this->event);

        Log::info('ExtractEpicsFromTranscriptJob: done', [
            'calendar_event_id' => $this->event->id,
            'created'           => $result['created']->count(),
            'updated'           => $result['updated']->count(),
        ]);
    }
}
