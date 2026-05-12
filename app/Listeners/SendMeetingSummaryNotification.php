<?php

namespace App\Listeners;

use App\Events\MeetingSummaryGenerated;
use App\Models\Issue;
use App\Models\MeetingSummary;
use App\Models\MeetingSummaryTemplate;
use App\Models\TeamNotificationSetting;
use App\Models\TelegramChatRegistration;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class SendMeetingSummaryNotification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 1;

    public function handle(MeetingSummaryGenerated $event): void
    {
        $summary = $event->summary;
        $calendarEvent = $summary->calendarEvent;

        if (! $calendarEvent?->source?->user) {
            return;
        }

        $user = $calendarEvent->source->user;
        $teams = $user->teams;

        if ($teams->isEmpty()) {
            return;
        }

        $calendarEvent->loadMissing(['participants', 'issues.assignee']);

        $newTasks = Issue::forMeeting($calendarEvent->id)->with('assignee')->get();

        $updatedTasks = Issue::whereHas('comments', fn ($q) =>
            $q->where('calendar_event_id', $calendarEvent->id)->whereNull('user_id')
        )
            ->whereNotIn('id', $newTasks->pluck('id'))
            ->with('assignee')
            ->get();

        foreach ($teams as $team) {
            $settings = TeamNotificationSetting::query()
                ->where('team_id', $team->id)
                ->where('event_type', 'meeting_summary')
                ->where('enabled', true)
                ->where('notifiable_type', TelegramChatRegistration::class)
                ->with('notifiable')
                ->get();

            $template = Cache::remember(
                "meeting_summary_template:{$team->id}",
                300,
                fn () => MeetingSummaryTemplate::where('team_id', $team->id)->first()
            );

            foreach ($settings as $setting) {
                /** @var TelegramChatRegistration $registration */
                $registration = $setting->notifiable;

                if (! $registration || ! $registration->telegram_chat_id) {
                    continue;
                }

                $this->send($registration, $summary, $newTasks, $updatedTasks, $template);
            }
        }
    }

    private function send(TelegramChatRegistration $registration, MeetingSummary $summary, Collection $newTasks, Collection $updatedTasks, ?MeetingSummaryTemplate $template): void
    {
        try {
            $text = $this->formatMessage($summary, $newTasks, $updatedTasks, $template);

            $telegram = new Api(config('telegram.bot_token'));
            $params = [
                'chat_id' => $registration->telegram_chat_id,
                'text' => $text,
                'parse_mode' => 'HTML',
            ];

            if ($registration->message_thread_id) {
                $params['message_thread_id'] = $registration->message_thread_id;
            }

            $telegram->sendMessage($params);
        } catch (\Throwable $e) {
            Log::warning('SendMeetingSummaryNotification: failed to send Telegram message', [
                'telegram_chat_id' => $registration->telegram_chat_id,
                'calendar_event_id' => $summary->calendar_event_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatMessage(MeetingSummary $summary, Collection $newTasks, Collection $updatedTasks, ?MeetingSummaryTemplate $template): string
    {
        $lines = [];
        $lines[] = '📋 <b>Meeting Summary</b>';
        $lines[] = '';
        $lines[] = '<b>'.e($summary->title).'</b>';
        $lines[] = '';

        $attendees = $summary->calendarEvent->participants->pluck('name')->filter()->values();
        if ($attendees->isNotEmpty()) {
            $lines[] = '👥 <b>Участники:</b> '.e($attendees->implode(', '));
            $lines[] = '';
        }

        $sections = $template?->sections ?? MeetingSummaryTemplate::DEFAULT_SECTIONS;

        $renderers = [
            'key_points'           => fn () => $this->renderKeyPoints($summary),
            'decisions'            => fn () => $this->renderDecisions($summary),
            'tasks'                => fn () => $this->renderTasks($newTasks, $updatedTasks),
            'commitments'          => fn () => $this->renderCommitments($summary),
            'repeated_discussions' => fn () => $this->renderRepeatedDiscussions($summary),
        ];

        foreach ($sections as $section) {
            if (isset($renderers[$section])) {
                $block = ($renderers[$section])();
                if ($block) {
                    $lines[] = $block;
                }
            }
        }

        return trim(implode("\n", $lines));
    }

    private function renderKeyPoints(MeetingSummary $summary): ?string
    {
        if (empty($summary->key_points)) {
            if (! $summary->summary) {
                return null;
            }
            return $this->markdownToTelegramHtml($summary->summary);
        }

        $lines = ['<b>Ключевые тезисы:</b>'];
        foreach ($summary->key_points as $point) {
            $lines[] = '• '.e($point);
        }

        return implode("\n", $lines);
    }

    private function renderDecisions(MeetingSummary $summary): ?string
    {
        if (empty($summary->decisions)) {
            return null;
        }

        $lines = ['<b>Решения:</b>'];
        foreach ($summary->decisions as $decision) {
            $lines[] = '• '.e($decision);
        }

        return implode("\n", $lines);
    }

    private function renderTasks(Collection $newTasks, Collection $updatedTasks): ?string
    {
        if ($newTasks->isEmpty() && $updatedTasks->isEmpty()) {
            return null;
        }

        $lines = [];

        if ($newTasks->isNotEmpty()) {
            $lines[] = '✨ <b>Новые задачи и цели:</b>';
            foreach ($newTasks as $task) {
                $assignee = $task->assignee?->name ?? '—';
                $lines[] = '• '.$this->inlineMarkdown($task->title).' ('.$assignee.')';
            }
        }

        if ($updatedTasks->isNotEmpty()) {
            if ($newTasks->isNotEmpty()) {
                $lines[] = '';
            }
            $lines[] = '✨ <b>Обновлённые задачи и цели:</b>';
            foreach ($updatedTasks as $task) {
                $assignee = $task->assignee?->name ?? '—';
                $lines[] = '• '.$this->inlineMarkdown($task->title).' ['.$task->status.'] ('.$assignee.')';
            }
        }

        return implode("\n", $lines);
    }

    private function renderCommitments(MeetingSummary $summary): ?string
    {
        $commitments = $summary->commitments ?? [];
        if (empty($commitments)) {
            return null;
        }

        $lines = ['<b>Обязательства:</b>'];
        foreach ($commitments as $c) {
            $who = e($c['who'] ?? '—');
            $what = e($c['what'] ?? '');
            $deadline = isset($c['deadline']) && $c['deadline']
                ? ' <i>('.e($c['deadline']).')</i>'
                : '';
            $lines[] = "• <b>{$who}</b>: {$what}{$deadline}";
        }

        return implode("\n", $lines);
    }

    private function renderRepeatedDiscussions(MeetingSummary $summary): ?string
    {
        $repeated = $summary->repeated_discussions ?? [];
        if (empty($repeated)) {
            return null;
        }

        $lines = ['🔁 <b>Повторяющиеся обсуждения:</b>'];
        foreach ($repeated as $item) {
            $newDecision = e($item['new_decision'] ?? '');
            $prevDecision = e($item['previous_decision'] ?? '');
            $prevDate = $item['previous_date'] ?? '';
            $prevTitle = $item['previous_meeting_title'] ?? '';

            $when = $prevDate ? ' ('.\Carbon\Carbon::parse($prevDate)->format('d.m.Y').')' : '';

            $eventId = $item['previous_calendar_event_id'] ?? null;
            $meetingLink = $eventId
                ? config('app.frontend_url').'/dashboard/meetings/'.$eventId
                : null;

            $titlePart = $meetingLink
                ? '<a href="'.e($meetingLink).'">'.e($prevTitle ?: 'встреча').'</a>'
                : ($prevTitle ? '«'.e($prevTitle).'»' : '');

            $where = $titlePart ? ' на '.$titlePart : '';

            $lines[] = "⚠️ «{$newDecision}»";
            $lines[] = "   Уже решалось{$when}{$where}: «{$prevDecision}»";
        }

        return implode("\n", $lines);
    }

    private function markdownToTelegramHtml(string $markdown): string
    {
        $rows = explode("\n", $markdown);
        $result = [];
        $skipNextRow = false;

        foreach ($rows as $line) {
            // Table separator |---|---| — skip, but mark next row is data (not header)
            if (preg_match('/^\|[-| :]+\|$/', trim($line))) {
                $skipNextRow = false;

                continue;
            }

            // Table row
            if (preg_match('/^\|(.+)\|$/', trim($line))) {
                if ($skipNextRow) {
                    // Header row — skip, treat next as data
                    $skipNextRow = false;

                    continue;
                }
                $cells = array_values(array_filter(
                    array_map('trim', explode('|', trim($line, '|'))),
                    fn ($c) => $c !== '',
                ));
                $escaped = array_map(fn ($c) => e(strip_tags($c)), $cells);
                $result[] = '• '.implode(' — ', $escaped);

                continue;
            }

            // Headings ## → <b>text</b>
            if (preg_match('/^#{1,6}\s+(.+)$/', $line, $m)) {
                $result[] = '<b>'.e(trim($m[1])).'</b>';

                continue;
            }

            // List items - text → • text
            if (preg_match('/^[-*]\s+(.+)$/', $line, $m)) {
                $text = $this->inlineMarkdown($m[1]);
                $result[] = '• '.$text;

                continue;
            }

            // Empty line
            if (trim($line) === '') {
                $result[] = '';

                continue;
            }

            // Regular text
            $result[] = $this->inlineMarkdown($line);
        }

        $output = implode("\n", $result);
        // Collapse 3+ newlines to 2
        $output = preg_replace('/\n{3,}/', "\n\n", $output);

        return trim($output);
    }

    private function inlineMarkdown(string $text): string
    {
        // Escape HTML first, then convert **bold** and _italic_
        $text = e($text);
        $text = preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', $text);
        $text = preg_replace('/__(.+?)__/', '<b>$1</b>', $text);
        $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<i>$1</i>', $text);
        $text = preg_replace('/(?<!_)_(?!_)(.+?)(?<!_)_(?!_)/', '<i>$1</i>', $text);

        return $text;
    }
}
