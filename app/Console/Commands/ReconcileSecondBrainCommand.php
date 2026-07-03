<?php

namespace App\Console\Commands;

use App\Services\SecondBrain\SecondBrainReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Converges Docker to the per-org desired state in `second_brain_instances`.
 *
 * This is the command the dedicated `brain-orchestrator` service runs with
 * `--watch`; it is also safe to run once, ad hoc, or scheduled. A global cache
 * lock guarantees only one convergence runs at a time.
 */
class ReconcileSecondBrainCommand extends Command
{
    protected $signature = 'brain:reconcile
        {--org= : Reconcile only this organization id}
        {--watch : Loop forever, reconciling every --interval seconds}
        {--interval= : Watch loop interval in seconds (default from config)}';

    protected $description = 'Reconcile per-organization second-brain containers with the desired state in the database';

    public function handle(SecondBrainReconciler $reconciler): int
    {
        $onlyOrg = $this->option('org') !== null ? (int) $this->option('org') : null;

        if (! $this->option('watch')) {
            $this->runOnce($reconciler, $onlyOrg);

            return self::SUCCESS;
        }

        $interval = (int) ($this->option('interval') ?: config('second_brain.reconcile_interval', 15));
        $interval = max(3, $interval);
        $this->info("second-brain: watching (every {$interval}s)");

        while (true) {
            $this->runOnce($reconciler, $onlyOrg);
            sleep($interval);
        }
    }

    private function runOnce(SecondBrainReconciler $reconciler, ?int $onlyOrg): void
    {
        // Only one convergence at a time (the watch loop + a toggle-triggered run
        // must not race). block() up to a few seconds, then skip this tick.
        $lock = Cache::lock('second-brain:reconcile', 300);

        try {
            $lock->block(5);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            $this->warn('second-brain: another reconcile holds the lock, skipping this tick');

            return;
        }

        try {
            $summary = $reconciler->reconcile($onlyOrg);
            $this->line(sprintf(
                '[%s] second-brain reconciled: started=%d stopped=%d errors=%d skipped=%d',
                now()->toIso8601String(),
                $summary['started'],
                $summary['stopped'],
                $summary['errors'],
                $summary['skipped'],
            ));
        } finally {
            optional($lock)->release();
        }
    }
}
