<?php

namespace App\Jobs;

use App\Models\Followup;
use App\Models\User;
use App\Services\Followup\FollowupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RegenerateFollowupJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $calendarEventId,
        public int $userId,
    ) {}

    public function handle(FollowupService $service): void
    {
        $user = User::query()->find($this->userId);
        if (! $user) {
            Log::warning('Followup regeneration skipped: user not found', [
                'calendar_event_id' => $this->calendarEventId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        $followup = Followup::query()
            ->with(['calendarEvent', 'team', 'user', 'methodology'])
            ->where('calendar_event_id', $this->calendarEventId)
            ->owned($this->userId)
            ->latest('id')
            ->first();

        if (! $followup) {
            Log::warning('Followup regeneration skipped: followup not found or access denied', [
                'calendar_event_id' => $this->calendarEventId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        $service->regenerate($followup, $user);
    }
}
