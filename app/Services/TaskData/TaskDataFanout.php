<?php

namespace App\Services\TaskData;

use App\Jobs\SendTaskDataUploadReportJob;
use App\Models\TaskDataUpload;
use App\Models\User;
use App\Services\Issue\IssueAutoPipelineDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

/**
 * The downstream that runs AFTER task-data issues are persisted.
 *
 * Shared single source of truth so the flag-off job (ProcessTaskDataUploadJob) and the gated
 * approve replay (ApproveExtractionPlanService) run the identical tail — no drift. Mirrors the
 * exact sequence of ProcessTaskDataUploadJob's former lines 57-89.
 */
class TaskDataFanout
{
    /**
     * @param  Collection<int, \App\Models\Issue>  $created
     * @param  Collection<int, \App\Models\Issue>  $updated
     */
    public function afterIssues(TaskDataUpload $upload, User $user, Collection $created, Collection $updated): void
    {
        $allIssueIds = $created->pluck('id')
            ->merge($updated->pluck('id'))
            ->filter()
            ->values()
            ->all();

        if ($allIssueIds !== []) {
            app(IssueAutoPipelineDispatcher::class)->dispatchForStandalone($allIssueIds);
        }

        $updatedIds = $updated->pluck('id')->filter()->values()->all();

        $upload->update([
            'status'            => 'done',
            'issues_created'    => $created->count(),
            'issues_updated'    => $updated->count(),
            'updated_issue_ids' => $updatedIds,
        ]);

        Log::info('task_data_upload.done', [
            'upload_id'      => $upload->id,
            'team_id'        => $upload->team_id,
            'issues_created' => $created->count(),
            'issues_updated' => $updated->count(),
        ]);

        $this->notifyIncompleteIssues($upload, $user, $created);

        SendTaskDataUploadReportJob::dispatch(
            $upload,
            $created->pluck('id')->all(),
            $updatedIds,
        );
    }

    /**
     * Check created issues for missing assignee/due_date and TG-notify the uploader.
     * Mirrors IncompleteIssuesNotifier logic but without CalendarEvent dependency.
     */
    private function notifyIncompleteIssues(TaskDataUpload $upload, User $uploader, Collection $createdIssues): void
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
            Log::warning('TaskDataFanout: incomplete issues notify failed', [
                'upload_id' => $upload->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
