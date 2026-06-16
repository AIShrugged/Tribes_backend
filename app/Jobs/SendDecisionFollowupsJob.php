<?php

namespace App\Jobs;

use App\Models\Decision;
use App\Services\Decisions\DecisionFollowupNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendDecisionFollowupsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(DecisionFollowupNotifier $notifier): void
    {
        $delayMinutes = (int) config('decisions.followup.delay_minutes', 120);
        $cutoff = now()->subMinutes($delayMinutes);

        $decisions = Decision::query()
            ->whereNotNull('author_user_id')
            ->whereHas('calendarEvent', function ($q) use ($cutoff) {
                $q->where('starts_at', '<=', $cutoff);
            })
            ->whereDoesntHave('issues')
            ->whereDoesntHave('followups')
            ->with(['authorUser.telegramUser', 'calendarEvent'])
            ->get();

        foreach ($decisions as $decision) {
            $notifier->send($decision);
        }
    }
}
