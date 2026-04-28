<?php

namespace App\Console\Commands;

use App\Models\MeetingSummary;
use App\Services\Decisions\ExtractDecisionsService;
use Illuminate\Console\Command;

class BackfillDecisionsCommand extends Command
{
    protected $signature = 'decisions:backfill {--summary-id= : Process only this summary id} {--skip-existing : Skip summaries that already have decisions in the decisions table}';

    protected $description = 'Backfill decisions table from existing meeting_summaries.decisions JSON';

    public function handle(ExtractDecisionsService $extractor): int
    {
        $query = MeetingSummary::query()->whereNotNull('decisions');

        if ($id = $this->option('summary-id')) {
            $query->where('id', $id);
        }

        if ($this->option('skip-existing')) {
            $query->whereDoesntHave('decisions');
        }

        $summaries = $query->get()->filter(fn (MeetingSummary $s) => ! empty($s->decisions));

        $this->info(sprintf('Backfilling %d summaries...', $summaries->count()));

        $totalCreated = 0;
        foreach ($summaries as $summary) {
            try {
                $count = $extractor->extract($summary);
                $totalCreated += $count;
                $this->line(sprintf(
                    '  summary=%d ce=%d → %d decisions',
                    $summary->id,
                    $summary->calendar_event_id,
                    $count
                ));
            } catch (\Throwable $e) {
                $this->error(sprintf('  summary=%d failed: %s', $summary->id, $e->getMessage()));
            }
        }

        $this->info(sprintf('Done. %d decisions created/updated.', $totalCreated));

        return self::SUCCESS;
    }
}
