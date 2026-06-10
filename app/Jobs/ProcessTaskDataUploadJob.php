<?php

namespace App\Jobs;

use App\Exceptions\ContentNotRelevantException;
use App\Models\ExtractionPlan;
use App\Models\TaskDataUpload;
use App\Models\Team;
use App\Models\User;
use App\Services\Extraction\ExtractionPlanSections;
use App\Services\TaskData\TaskDataFanout;
use App\Services\TaskData\TaskDataIssueExtractionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessTaskDataUploadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 120;

    /**
     * @param  int     $uploadId
     * @param  string  $content  Already-extracted and normalized text content.
     *                           Read in the HTTP-serving container so the queue
     *                           worker doesn't need filesystem access to the upload.
     */
    public function __construct(
        private readonly int $uploadId,
        private readonly string $content,
    ) {
    }

    public function handle(
        TaskDataIssueExtractionService $extractionService,
    ): void {
        $upload = TaskDataUpload::findOrFail($this->uploadId);
        $team = Team::findOrFail($upload->team_id);
        $user = User::findOrFail($upload->user_id);

        try {
            $upload->update(['status' => 'extracting']);

            // Content already extracted + normalized by the service before dispatch.
            // 'extracting' status is brief but honest — we're preparing the text.

            $upload->update(['status' => 'analyzing']);

            $items = $extractionService->extractItems($this->content, $upload, $team);

            // Pre-moderation is the concept for DASHBOARD task-data uploads: compute the plan WITHOUT
            // writing, stage it for human review, and STOP before persist + fanout (no DB rows, no
            // Telegram). Telegram-origin uploads (source_telegram_chat_id set) are excluded — that path
            // has no dashboard reviewer and expects an immediate report back to the chat, like Recall.
            if ($upload->source_telegram_chat_id === null) {
                $this->stagePlan($extractionService, $upload, $team, $user, $items);

                return;
            }

            $upload->update(['status' => 'deduplicating']);

            $result = $extractionService->persistItems($items, $team, $user, $upload);

            app(TaskDataFanout::class)->afterIssues($upload, $user, $result['created'], $result['updated']);
        } catch (ContentNotRelevantException $e) {
            $upload->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::info('ProcessTaskDataUploadJob: content rejected as not relevant', [
                'upload_id' => $upload->id,
            ]);
        } catch (\Throwable $e) {
            $upload->update([
                'status'        => 'failed',
                'error_message' => 'Could not process uploaded file',
            ]);

            Log::error('ProcessTaskDataUploadJob: failed', [
                'upload_id' => $upload->id,
                'error'     => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Stage the computed plan for moderation. Single-writer create (no barrier needed — task-data has
     * one section), so the upload row goes straight to pending_review.
     *
     * @param  array<int, array>  $items
     */
    private function stagePlan(
        TaskDataIssueExtractionService $extractionService,
        TaskDataUpload $upload,
        Team $team,
        User $user,
        array $items,
    ): void {
        $section = ExtractionPlanSections::issues($extractionService->computePlan($items, $team, $upload));
        $section['team_id'] = $team->id;
        $section['user_id'] = $user->id;

        ExtractionPlan::updateOrCreate(
            ['sourceable_type' => TaskDataUpload::class, 'sourceable_id' => $upload->id],
            [
                'status'            => ExtractionPlan::STATUS_PENDING_REVIEW,
                'team_id'           => $team->id,
                'organization_id'   => $team->organization_id,
                'user_id'           => $user->id,
                'expected_sections' => ['issues'],
                'section_status'    => ['issues' => 'ready'],
                'plan'              => ['issues' => $section, 'decisions' => null, 'review' => null],
            ],
        );

        $upload->update(['status' => 'pending_review']);

        Log::info('task_data_upload.pending_review', ['upload_id' => $upload->id]);
    }

    /**
     * Fired by the queue when the job times out or throws past handle()'s own catch.
     * Marks a stranded (non-done) row failed so the Upload Log shows a real status and
     * the frontend detail poll terminates — there is no separate reaper.
     */
    public function failed(\Throwable $e): void
    {
        TaskDataUpload::where('id', $this->uploadId)
            ->where('status', '!=', 'done')
            ->update(['status' => 'failed', 'error_message' => 'Could not process uploaded file']);
    }
}
