<?php

namespace App\Jobs;

use App\Models\MeetingSummary;
use App\Models\Team;
use App\Services\Meeting\DetectRepeatedDiscussionsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DetectRepeatedDiscussionsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public MeetingSummary $summary,
        public Team $team,
    ) {}

    public function handle(DetectRepeatedDiscussionsService $service): void
    {
        $summary = $this->summary->fresh();

        if (! $summary) {
            return;
        }

        try {
            $matches = $service->detect($summary, $this->team);
            $summary->update(['repeated_discussions' => $matches]);
        } catch (\Throwable $e) {
            Log::error('DetectRepeatedDiscussionsJob failed', [
                'meeting_summary_id' => $this->summary->id,
                'team_id' => $this->team->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
