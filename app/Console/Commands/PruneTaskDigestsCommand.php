<?php

namespace App\Console\Commands;

use App\Models\TaskDigest;
use Illuminate\Console\Command;

class PruneTaskDigestsCommand extends Command
{
    protected $signature = 'digests:prune';

    protected $description = 'Delete task_digests rows past their expires_at timestamp.';

    public function handle(): int
    {
        $deleted = TaskDigest::query()->where('expires_at', '<', now())->delete();

        $this->info("Pruned {$deleted} expired task_digests row(s).");

        return self::SUCCESS;
    }
}
