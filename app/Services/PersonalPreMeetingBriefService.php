<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\User;
use Carbon\Carbon;
use App\Models\MeetingBriefDedup;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

/**
 * Sends personal pre-meeting briefs to each internal participant's personal Telegram chat,
 * 10-20 minutes before the meeting starts.
 *
 * Parallel to the existing group-chat `PreMeetingBriefService` (which sends to a TeamNotificationSetting
 * channel). Different audience, different filter rules, different formatting concerns.
 *
 * H2 fix: only sends to users who are members of the meeting-owning organization. External
 * attendees (e.g., customers with linked TelegramUser) do NOT receive internal briefs.
 */
class PersonalPreMeetingBriefService
{
    private const TELEGRAM_MAX_LENGTH = 4096;

    public function sendBriefs(?string $testTelegramUserId = null): int
    {
        if (! config('features.enable_personal_premeeting_brief', false)) {
            return 0;
        }

        $from = Carbon::now()->addMinutes(10);
        $to = Carbon::now()->addMinutes(20);

        $events = CalendarEvent::query()
            ->whereBetween('starts_at', [$from, $to])
            ->with([
                'profiles.user.telegramUser',
                'profiles.user.organizations',
                'source.user.organizations',
                'sources.user.organizations',
                'generalAgenda',
                'meetingSummary:id,calendar_event_id,summary',
            ])
            ->get();

        $sent = 0;
        foreach ($events as $event) {
            $ownerOrgIds = $this->resolveOwnerOrgIds($event);

            // H2: if we can't determine which org owns the event, SKIP entirely.
            // Better fail-closed than send internal data to potential externals.
            if (empty($ownerOrgIds)) {
                continue;
            }

            foreach ($event->profiles as $profile) {
                $user = $profile->user;
                if (! $user || ! $user->telegramUser) {
                    continue;
                }

                // External attendee filter — only members of an owning org
                if (! $this->isUserMemberOfAnyOrg($user, $ownerOrgIds)) {
                    continue;
                }

                // Atomic dedup via Postgres INSERT … ON CONFLICT DO NOTHING.
                // Survives Redis/container restarts (cache wipes used to cause duplicates).
                $inserted = MeetingBriefDedup::query()->insertOrIgnore([
                    'calendar_event_id' => $event->id,
                    'brief_kind' => MeetingBriefDedup::KIND_PERSONAL,
                    'recipient_id' => $user->id,
                    'sent_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($inserted === 0) {
                    continue; // already sent
                }

                $text = $this->formatPersonalMessage($event, $user);
                $chatId = $testTelegramUserId ?? $user->telegramUser->telegram_user_id;

                $this->send($chatId, $text);
                $sent++;
            }
        }

        return $sent;
    }

    private function resolveOwnerOrgIds(CalendarEvent $event): array
    {
        // Try legacy `source` first (single)
        $orgIds = collect();
        if ($event->source?->user) {
            $orgIds = $event->source->user->organizations->pluck('id');
        }

        // Then collect all org IDs from all sources (modern many-to-many)
        foreach ($event->sources as $source) {
            if ($source->user) {
                $orgIds = $orgIds->merge($source->user->organizations->pluck('id'));
            }
        }

        return $orgIds->unique()->values()->all();
    }

    private function isUserMemberOfAnyOrg(User $user, array $orgIds): bool
    {
        $userOrgIds = $user->organizations->pluck('id')->all();
        return ! empty(array_intersect($orgIds, $userOrgIds));
    }

    private function formatPersonalMessage(CalendarEvent $event, User $user): string
    {
        $lines = [];
        $lines[] = '📅 <b>Через ~15 минут:</b>';
        $lines[] = '';
        $lines[] = '<b>' . e($event->title) . '</b>';

        $start = Carbon::parse($event->starts_at);
        $end = $event->ends_at ? Carbon::parse($event->ends_at) : null;
        if ($end) {
            $duration = $start->diffInMinutes($end);
            $lines[] = '🕐 ' . $start->format('H:i') . ' — ' . $end->format('H:i') . " ({$duration} мин)";
        } else {
            $lines[] = '🕐 ' . $start->format('H:i');
        }

        if ($event->url) {
            $lines[] = '🔗 <a href="' . e($event->url) . '">Подключиться</a>';
        }

        $agendaJson = $event->generalAgenda?->raw_json ?? [];

        // 📋 Общая агенда — goal + main_problem + discussion_topics with descriptions
        $goal = $agendaJson['meeting_goal'] ?? null;
        $mainProblem = $agendaJson['main_problem'] ?? null;
        $topics = $agendaJson['discussion_topics'] ?? [];
        $hasAgendaContent = (is_string($goal) && $goal !== '')
            || (is_string($mainProblem) && $mainProblem !== '')
            || (is_array($topics) && ! empty($topics));

        if ($hasAgendaContent) {
            $lines[] = '';
            $lines[] = '📋 <b>Общая агенда:</b>';

            if (is_string($goal) && $goal !== '') {
                $lines[] = '';
                $lines[] = '🎯 <b>Цель:</b> ' . e($goal);
            }

            if (is_string($mainProblem) && $mainProblem !== '') {
                $lines[] = '';
                $lines[] = '⚠️ <b>Главная проблема:</b> ' . e($mainProblem);
            }

            if (is_array($topics) && ! empty($topics)) {
                $lines[] = '';
                $lines[] = '<b>Темы:</b>';
                foreach ($topics as $i => $topic) {
                    $title = is_array($topic) ? ($topic['title'] ?? '') : (string) $topic;
                    $desc = is_array($topic) ? trim((string) ($topic['description'] ?? '')) : '';
                    $lines[] = ($i + 1) . '. <b>' . e($title) . '</b>';
                    if ($desc !== '') {
                        $lines[] = '   <i>' . e($desc) . '</i>';
                    }
                }
            }
        }

        // Personal commitments_check items belonging to user (issue_id → assignee_id matching)
        $personalCommitments = $this->extractPersonalCommitments($agendaJson, $user);
        if (! empty($personalCommitments)) {
            $lines[] = '';
            $lines[] = '💼 <b>От тебя ожидают:</b>';
            foreach ($personalCommitments as $c) {
                $text = (string) ($c['commitment'] ?? $c['text'] ?? '');
                $status = (string) ($c['status'] ?? '');
                $marker = $status === 'done' ? '✓' : '•';
                $line = "{$marker} " . e($text);
                if (! empty($c['deadline'])) {
                    $line .= ' <i>(до ' . e((string) $c['deadline']) . ')</i>';
                }
                if (! empty($c['question'])) {
                    $line .= ' — <i>' . e((string) $c['question']) . '</i>';
                }
                $lines[] = $line;
            }
        }

        // Previous meeting summary (brief excerpt)
        $prev = $agendaJson['previous_summary'] ?? null;
        if (is_array($prev) && ! empty($prev['key_points'])) {
            $lines[] = '';
            $lines[] = '📋 <b>С прошлой встречи:</b>';
            foreach (array_slice((array) $prev['key_points'], 0, 3) as $kp) {
                $lines[] = '• ' . e((string) $kp);
            }
        }

        // 🧠 AI tips — reuse meetings_advice generated at 08:30 in task_digests.
        // Same content as morning brief shows, but specifically for THIS meeting.
        $tips = $this->loadMeetingTips($user, $event->id);
        if (! empty($tips)) {
            $lines[] = '';
            $lines[] = '🧠 <b>Советы:</b>';
            foreach (array_slice($tips, 0, 3) as $tip) {
                $lines[] = '• ' . e($tip);
            }
        }

        $text = implode("\n", $lines);

        if (mb_strlen($text) > self::TELEGRAM_MAX_LENGTH) {
            $text = mb_substr($text, 0, self::TELEGRAM_MAX_LENGTH - 1);
            $lastNl = mb_strrpos($text, "\n");
            if ($lastNl !== false) {
                $text = mb_substr($text, 0, $lastNl);
            }
        }

        return $text;
    }

    /**
     * Load AI preparation tips for a meeting from any of $user's daily task_digests today.
     *
     * Tips are pre-generated by MeetingsAdviceService at 08:30 and stored in
     * task_digests.content[meetings_advice][]. Multi-org-safe: collects from any digest.
     *
     * @return array<int, string>
     */
    private function loadMeetingTips(User $user, int $meetingId): array
    {
        $digests = \App\Models\TaskDigest::query()
            ->where('user_id', $user->id)
            ->where('period_type', 'daily')
            ->whereDate('period_start', \Carbon\Carbon::today()->toDateString())
            ->get();

        $tips = [];
        foreach ($digests as $digest) {
            foreach ((array) (($digest->content['meetings_advice'] ?? [])) as $item) {
                if (! is_array($item)) continue;
                if ((int) ($item['meeting_id'] ?? 0) !== $meetingId) continue;
                $tips = array_merge($tips, array_values((array) ($item['tips'] ?? [])));
            }
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($t) => is_string($t) ? trim($t) : null, $tips),
            fn ($t) => $t !== null && $t !== ''
        )));
    }

    /**
     * Filter agenda commitments_check[] to entries belonging to $user.
     *
     * Primary: ID-based — commitments_check[].issue_id → Issue.assignee_id == user.id.
     * Fallback: name-based for entries without issue_id, using User.name + Profile.names.
     */
    private function extractPersonalCommitments(array $agendaJson, User $user): array
    {
        $items = (array) ($agendaJson['commitments_check'] ?? []);
        if (empty($items)) return [];

        $issueIds = collect($items)->pluck('issue_id')->filter()->unique()->all();
        $assigneeByIssue = empty($issueIds) ? [] :
            \App\Models\Issue::whereIn('id', $issueIds)->pluck('assignee_id', 'id')->all();

        $user->loadMissing('profiles');
        $aliases = collect([$user->name])
            ->merge($user->profiles?->pluck('name') ?? [])
            ->filter()
            ->map(fn ($n) => mb_strtolower(trim((string) $n)))
            ->filter(fn ($n) => $n !== '')
            ->unique()
            ->all();

        $out = [];
        foreach ($items as $c) {
            if (! is_array($c)) continue;

            $isMine = false;
            $iid = $c['issue_id'] ?? null;
            if ($iid !== null && isset($assigneeByIssue[$iid])) {
                $isMine = ((int) $assigneeByIssue[$iid]) === (int) $user->id;
            } elseif (! empty($aliases)) {
                $person = mb_strtolower(trim((string) ($c['person'] ?? '')));
                $isMine = $person !== '' && in_array($person, $aliases, true);
            }

            if ($isMine) $out[] = $c;
        }
        return $out;
    }

    private function send(string $chatId, string $text): void
    {
        try {
            $telegram = new Api(config('telegram.bot_token'));
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('PersonalPreMeetingBriefService: send failed', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
