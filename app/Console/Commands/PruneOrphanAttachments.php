<?php

namespace App\Console\Commands;

use App\Models\IssueAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PruneOrphanAttachments extends Command
{
    protected $signature = 'attachments:prune-orphans
                            {--hours= : Override TTL in hours (default: IssueAttachment::ORPHAN_TTL_HOURS)}';

    protected $description = 'Delete pending issue attachments that were never bound to an issue.';

    public function handle(): int
    {
        $hours   = (int) ($this->option('hours') ?? IssueAttachment::ORPHAN_TTL_HOURS);
        $cutoff  = now()->subHours($hours);
        $disk    = (string) config('filesystems.issue_attachments_disk', config('filesystems.default', 'local'));
        $deleted = 0;

        // lockForUpdate prevents a concurrent IssueController::store binding UPDATE
        // from racing with our DELETE — without it the sequence is:
        //   cleanup SELECT → store UPDATE binds row → cleanup DELETE file → DB row points to missing file
        DB::transaction(function () use ($cutoff, $disk, &$deleted) {
            IssueAttachment::query()
                ->whereNull('issue_id')
                ->where('uploaded_at', '<', $cutoff)
                ->lockForUpdate()
                ->chunkById(100, function ($orphans) use ($disk, &$deleted) {
                    foreach ($orphans as $orphan) {
                        try {
                            Storage::disk($disk)->delete($orphan->file_path);
                        } catch (\Throwable $e) {
                            // File missing from storage is acceptable — always delete the DB row
                            // to prevent perpetual re-queuing of unresolvable orphans.
                            Log::warning('PruneOrphanAttachments: file not found on disk', [
                                'attachment_id' => $orphan->id,
                                'file_path'     => $orphan->file_path,
                                'error'         => $e->getMessage(),
                            ]);
                        }

                        $orphan->delete();
                        $deleted++;
                    }
                });
        });

        $this->info("Pruned {$deleted} pending attachment(s) older than {$hours} hours.");

        return Command::SUCCESS;
    }
}