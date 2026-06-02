<?php

namespace App\Jobs;

use App\Models\Issue;
use App\Models\TaskDataUpload;
use App\Models\Team;
use App\Models\User;
use App\Services\Issue\IssueAutoPipelineDispatcher;
use App\Services\TaskData\TaskDataIssueExtractionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

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

            $upload->update(['status' => 'deduplicating']);

            $result = $extractionService->persistItems($items, $team, $user, $upload);

            $allIssueIds = $result['created']->pluck('id')
                ->merge($result['updated']->pluck('id'))
                ->filter()
                ->values()
                ->all();

            if ($allIssueIds !== []) {
                app(IssueAutoPipelineDispatcher::class)->dispatchForStandalone($allIssueIds);
            }

            $upload->update([
                'status'         => 'done',
                'issues_created' => $result['created']->count(),
                'issues_updated' => $result['updated']->count(),
            ]);

            Log::info('task_data_upload.done', [
                'upload_id'      => $upload->id,
                'team_id'        => $team->id,
                'issues_created' => $result['created']->count(),
                'issues_updated' => $result['updated']->count(),
            ]);

            $this->notifyIncompleteIssues($upload, $user, $result['created']);

            SendTaskDataUploadReportJob::dispatch(
                $upload,
                $result['created']->pluck('id')->all(),
                $result['updated']->pluck('id')->all(),
            );
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

    /**
     * Check created issues for missing assignee/due_date and TG-notify the uploader.
     * Mirrors IncompleteIssuesNotifier logic but without CalendarEvent dependency.
     */
    private function notifyIncompleteIssues(TaskDataUpload $upload, User $uploader, $createdIssues): void
    {
        $incomplete = $createdIssues->filter(function ($issue) {
            return empty($issue->assignee_id) || empty($issue->due_date);
        });

        if ($incomplete->isEmpty()) {
            return;
        }

        $chatId = $uploader->telegramUser?->telegram_user_id;
        if (!$chatId) {
            return;
        }

        $frontendUrl = rtrim(config('app.frontend_url', ''), '/');
        $filename = e($upload->original_filename);

        $lines = [];
        $lines[] = "\xF0\x9F\x93\x8B Tasks from <b>\"{$filename}\"</b> need attention:";
        $lines[] = '';

        foreach ($incomplete->take(10) as $i => $issue) {
            $name = e($issue->name);
            $url = "{$frontendUrl}/dashboard/issues/{$issue->id}";
            $missing = [];
            if (empty($issue->assignee_id)) {
                $missing[] = 'no assignee';
            }
            if (empty($issue->due_date)) {
                $missing[] = 'no due date';
            }
            $lines[] = ($i + 1) . ". <a href=\"{$url}\">{$name}</a> — " . implode(', ', $missing);
        }

        if ($incomplete->count() > 10) {
            $lines[] = '... and ' . ($incomplete->count() - 10) . ' more';
        }

        $lines[] = '';
        $lines[] = 'Please fill in the missing fields in the dashboard.';

        try {
            $telegram = new Api(config('telegram.bot_token'));
            $telegram->sendMessage([
                'chat_id'                  => $chatId,
                'text'                     => implode("\n", $lines),
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ProcessTaskDataUploadJob: incomplete issues notify failed', [
                'upload_id' => $upload->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
