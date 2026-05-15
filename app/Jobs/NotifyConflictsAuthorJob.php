<?php

namespace App\Jobs;

use App\Services\Issue\ConflictAuthorNotifier;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

class NotifyConflictsAuthorJob implements ShouldQueueAfterCommit
{
    use Queueable;

    // tries=1: Telegram notifications must not duplicate on retry.
    public int $tries = 1;

    /**
     * @param  string[]  $conflictGroupUuids
     */
    public function __construct(
        public array $conflictGroupUuids,
    ) {}

    public function handle(ConflictAuthorNotifier $notifier): void
    {
        if (empty($this->conflictGroupUuids)) {
            return;
        }

        $notifier->notify($this->conflictGroupUuids);
    }
}
