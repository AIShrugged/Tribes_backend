<?php

namespace App\Jobs;

use App\Models\Issue;
use App\Models\TaskDataUpload;
use App\Models\TeamNotificationSetting;
use App\Models\TelegramChatRegistration;
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
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $uploader = $this->upload->user;
        $createdIssues = $this->createdIds !== [] ? Issue::whereIn('id', $this->createdIds)->get() : collect();
        $updatedIssues = $this->updatedIds !== [] ? Issue::whereIn('id', $this->updatedIds)->get() : collect();

        $text = $this->buildMessage($createdIssues, $updatedIssues);
        $recipients = $this->recipients();

        if ($recipients === []) {
            Log::info('SendTaskDataUploadReport: no Telegram recipients', [
                'upload_id' => $this->upload->id,
                'user_id'   => $uploader?->id,
            ]);
            return;
        }

        try {
            $telegram = new Api(config('telegram.bot_token'));
            foreach ($recipients as $recipient) {
                $params = [
                    'chat_id'    => $recipient['chat_id'],
                    'text'       => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ];

                if ($recipient['thread_id'] !== null) {
                    $params['message_thread_id'] = $recipient['thread_id'];
                }

                $telegram->sendMessage($params);
            }

            Log::info('SendTaskDataUploadReport: sent', [
                'upload_id' => $this->upload->id,
                'user_id'   => $uploader?->id,
                'recipients_count' => count($recipients),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SendTaskDataUploadReport: failed to send', [
                'upload_id' => $this->upload->id,
                'user_id'   => $uploader?->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function recipients(): array
    {
        $recipients = [];
        $add = function (?int $chatId, ?int $threadId = null) use (&$recipients): void {
            if (!$chatId) {
                return;
            }

            $key = $chatId.':'.($threadId ?? 'root');
            $recipients[$key] = ['chat_id' => $chatId, 'thread_id' => $threadId];
        };

        $add($this->upload->user?->telegramUser?->telegram_user_id);
        $add($this->upload->source_telegram_chat_id, $this->upload->source_telegram_thread_id);

        TeamNotificationSetting::query()
            ->where('team_id', $this->upload->team_id)
            ->where('event_type', 'meeting_summary')
            ->where('enabled', true)
            ->where('notifiable_type', TelegramChatRegistration::class)
            ->with('notifiable')
            ->get()
            ->each(function (TeamNotificationSetting $setting) use ($add): void {
                /** @var TelegramChatRegistration|null $registration */
                $registration = $setting->notifiable;
                $add($registration?->telegram_chat_id, $registration?->message_thread_id);
            });

        return array_values($recipients);
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
