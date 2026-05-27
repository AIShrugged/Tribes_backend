<?php

namespace App\Jobs;

use App\Models\Issue;
use App\Models\TaskDataUpload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

/**
 * Send a personal Telegram report to the uploader with a list of extracted tasks.
 *
 * Receives ID arrays (not Collections) to avoid stale model state between
 * dispatch and execution. Re-queries issues in handle().
 */
class SendTaskDataUploadReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  int[]  $createdIds
     * @param  int[]  $updatedIds
     */
    public function __construct(
        private readonly TaskDataUpload $upload,
        private readonly array $createdIds,
        private readonly array $updatedIds,
    ) {
    }

    public function handle(): void
    {
        $uploader = $this->upload->user;
        $telegramUserId = $uploader?->telegramUser?->telegram_user_id;

        if (!$telegramUserId) {
            Log::info('SendTaskDataUploadReport: uploader has no Telegram linked', [
                'upload_id' => $this->upload->id,
                'user_id'   => $uploader?->id,
            ]);
            return;
        }

        $createdIssues = $this->createdIds !== [] ? Issue::whereIn('id', $this->createdIds)->get() : collect();
        $updatedIssues = $this->updatedIds !== [] ? Issue::whereIn('id', $this->updatedIds)->get() : collect();

        $text = $this->buildMessage($createdIssues, $updatedIssues);

        try {
            $telegram = new Api(config('telegram.bot_token'));
            $telegram->sendMessage([
                'chat_id'    => $telegramUserId,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ]);

            Log::info('SendTaskDataUploadReport: sent', [
                'upload_id' => $this->upload->id,
                'user_id'   => $uploader->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SendTaskDataUploadReport: failed to send', [
                'upload_id' => $this->upload->id,
                'user_id'   => $uploader->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function buildMessage($createdIssues, $updatedIssues): string
    {
        $frontendUrl = config('app.frontend_url', '');
        $filename = e($this->upload->original_filename);

        $lines = [];
        $lines[] = "\xF0\x9F\x93\xA4 <b>Task data upload report</b>";
        $lines[] = "\"{$filename}\" processed";
        $lines[] = '';

        if ($createdIssues->isNotEmpty()) {
            $lines[] = "\xF0\x9F\x93\x8B <b>New tasks ({$createdIssues->count()}):</b>";
            foreach ($createdIssues->take(15) as $issue) {
                $name = e($issue->name);
                $link = $frontendUrl
                    ? "<a href=\"{$frontendUrl}/dashboard/issues/{$issue->id}\">{$name}</a>"
                    : $name;
                $assignee = $issue->assignee_name ? ' (' . e($issue->assignee_name) . ')' : '';
                $lines[] = "\xE2\x80\xA2 {$link}{$assignee}";
            }
            if ($createdIssues->count() > 15) {
                $lines[] = "  ... and " . ($createdIssues->count() - 15) . " more";
            }
        }

        if ($updatedIssues->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "\xF0\x9F\x94\x84 <b>Updated tasks ({$updatedIssues->count()}):</b>";
            foreach ($updatedIssues->take(10) as $issue) {
                $name = e($issue->name);
                $link = $frontendUrl
                    ? "<a href=\"{$frontendUrl}/dashboard/issues/{$issue->id}\">{$name}</a>"
                    : $name;
                $lines[] = "\xE2\x80\xA2 {$link}";
            }
            if ($updatedIssues->count() > 10) {
                $lines[] = "  ... and " . ($updatedIssues->count() - 10) . " more";
            }
        }

        if ($createdIssues->isEmpty() && $updatedIssues->isEmpty()) {
            $lines[] = "No tasks extracted from this document.";
        }

        return implode("\n", $lines);
    }
}
