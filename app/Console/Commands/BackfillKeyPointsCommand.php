<?php

namespace App\Console\Commands;

use App\Models\MeetingSummary;
use App\Services\Decisions\ExtractKeyPointsService;
use Illuminate\Console\Command;

class BackfillKeyPointsCommand extends Command
{
    protected $signature = 'decisions:backfill-key-points
                            {--summary-id= : Process only this summary id}
                            {--skip-existing : Skip summaries that already have rows in meeting_key_points}';

    protected $description = 'Backfill meeting_key_points table from existing meeting_summaries.key_points JSON';

    public function handle(ExtractKeyPointsService $extractor): int
    {
        $query = MeetingSummary::query()->whereNotNull('key_points');

        if ($id = $this->option('summary-id')) {
            $query->where('id', $id);
        }

        if ($this->option('skip-existing')) {
            $query->whereDoesntHave('keyPoints');
        }

        $summaries = $query->get()->filter(fn (MeetingSummary $s) => ! empty($s->key_points));

        $this->info(sprintf('Backfilling %d summaries...', $summaries->count()));

        $totalCreated = 0;
        foreach ($summaries as $summary) {
            try {
                $count = $extractor->extract($summary);
                $totalCreated += $count;
                $this->line(sprintf(
                    '  summary=%d ce=%d → %d key points',
                    $summary->id,
                    $summary->calendar_event_id,
                    $count
                ));
            } catch (\Throwable $e) {
                $this->error(sprintf('  summary=%d failed: %s', $summary->id, $e->getMessage()));
            }
        }

        $this->info(sprintf('Done. %d key points created.', $totalCreated));

        return self::SUCCESS;
    }
}