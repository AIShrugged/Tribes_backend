<?php

namespace App\Jobs;

use App\Models\Issue;
use App\Services\Issue\IncompleteContentNotifier;
use App\Services\Issue\IssueContentValidator;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ValidateAutoIssuesContentJob implements ShouldQueueAfterCommit
{
    use Queueable;

    // tries=1: notifications must not duplicate on retry.
    public int $tries = 1;

    /**
     * @param  int[]  $issueIds
     */
    public function __construct(
        public array $issueIds,
    ) {
        $this->onQueue('heavy');
    }

    public function handle(IssueContentValidator $validator, IncompleteContentNotifier $notifier): void
    {
        if (empty($this->issueIds)) {
            return;
        }

        $issues = Issue::query()
            ->whereIn('id', $this->issueIds)
            ->with(['user.telegramUser', 'sourceable'])
            ->get();

        $notifiedCount = 0;
        $checkedCount = 0;

        foreach ($issues as $issue) {
            $checkedCount++;
            $missing = $validator->validate($issue);
            if (empty($missing)) {
                continue;
            }
            $notifier->notify($issue, $missing);
            $notifiedCount++;
        }

        Log::info('ValidateAutoIssuesContentJob: done', [
            'checked'   => $checkedCount,
            'notified'  => $notifiedCount,
        ]);
    }
}
