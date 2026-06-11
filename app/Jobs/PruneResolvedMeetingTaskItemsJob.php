<?php

namespace App\Jobs;

use App\Models\MeetingTaskReviewItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PruneResolvedMeetingTaskItemsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(private readonly int $organizationId) {}

    public function handle(): void
    {
        $deleted = MeetingTaskReviewItem::query()
            ->where('progress', 'done')
            ->whereHas('review', fn ($q) => $q->where('organization_id', $this->organizationId))
            ->whereHas('issue', fn ($q) => $q->whereIn('status', ['done', 'closed', 'cancelled']))
            ->delete();

        Log::info('Pruned resolved meeting task items', [
            'organization_id' => $this->organizationId,
            'deleted' => $deleted,
        ]);
    }
}
