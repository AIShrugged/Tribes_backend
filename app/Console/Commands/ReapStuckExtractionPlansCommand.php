<?php

namespace App\Console\Commands;

use App\Models\CalendarEvent;
use App\Models\ExtractionPlan;
use App\Models\TaskDataUpload;
use App\Models\TranscriptUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Defense-in-depth anti-strand for the moderation barrier.
 *
 * The transcript barrier waits for all expected sections to report. If a producing
 * job is never dispatched or its compute fails to mark its section, the plan can sit
 * in 'collecting' forever. The section-failed hooks cover "dispatched-then-threw" only;
 * this reaper covers "never reported" by failing plans stuck in 'collecting' past a TTL.
 *
 * Only ever touches extraction_plans rows, which exist solely for gated MANUAL uploads —
 * zero Recall surface.
 */
class ReapStuckExtractionPlansCommand extends Command
{
    protected $signature = 'extraction:reap-stuck-plans {--minutes=30 : Age threshold for a collecting plan to be failed}';

    protected $description = 'Fail extraction plans stuck in collecting beyond the TTL (anti-strand for the moderation barrier).';

    public function handle(): int
    {
        $threshold = now()->subMinutes((int) $this->option('minutes'));

        $stuck = ExtractionPlan::where('status', ExtractionPlan::STATUS_COLLECTING)
            ->where('created_at', '<', $threshold)
            ->get();

        foreach ($stuck as $plan) {
            $plan->update(['status' => ExtractionPlan::STATUS_FAILED]);
            $this->failUploadRow($plan);

            Log::warning('extraction:reap-stuck-plans failed a stranded plan', [
                'plan_id' => $plan->id,
                'sourceable_type' => $plan->sourceable_type,
                'sourceable_id' => $plan->sourceable_id,
                'age_minutes' => (int) $this->option('minutes'),
            ]);
        }

        $this->info("Reaped {$stuck->count()} stuck extraction plan(s).");

        return self::SUCCESS;
    }

    /** Mark the originating upload-log row failed so the UI poll terminates. */
    private function failUploadRow(ExtractionPlan $plan): void
    {
        if ($plan->sourceable_type === TaskDataUpload::class) {
            TaskDataUpload::where('id', $plan->sourceable_id)
                ->where('status', '!=', 'done')
                ->update(['status' => 'failed', 'error_message' => 'Moderation timed out']);

            return;
        }

        if ($plan->sourceable_type === CalendarEvent::class) {
            TranscriptUpload::where('calendar_event_id', $plan->sourceable_id)
                ->whereNotIn('status', ['done', 'failed'])
                ->update(['status' => 'failed', 'error_message' => 'Moderation timed out']);
        }
    }
}
