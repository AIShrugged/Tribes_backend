<?php

namespace App\Console\Commands;

use App\Models\AgentCommandAudit;
use Illuminate\Console\Command;

/**
 * Nulls out the undo snapshot (inverse_payload) of agent command audit rows older
 * than N days. The audit RECORD (who did what, when) is kept long-term; only the
 * reversal data is purged — undo is a short window, and the snapshot may hold PII.
 */
class PurgeAgentCommandUndo extends Command
{
    protected $signature = 'agent:purge-command-undo {--days=7}';

    protected $description = 'Purge undo payloads (inverse_payload) from agent command audit rows older than N days.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $count = AgentCommandAudit::query()
            ->whereNotNull('inverse_payload')
            ->where('created_at', '<', $cutoff)
            ->update(['inverse_payload' => null]);

        $this->info("Purged undo payloads from {$count} audit row(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
