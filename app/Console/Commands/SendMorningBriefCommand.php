<?php

namespace App\Console\Commands;

use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Source;
use App\Models\TaskDigest;
use App\Models\User;
use App\Notifications\ProgressNotification;
use App\Services\Digest\TaskDigestService;
use App\Services\Today\TaskDeadlineGrouper;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class SendMorningBriefCommand extends Command
{
    protected $signature = 'meetings:send-morning-brief {--test-user= : Send all messages only to this Telegram user ID}';

    protected $description = 'Send morning brief with today\'s meetings and open tasks to each user';

    public function __construct(
        private readonly TaskDeadlineGrouper $taskGrouper,
        private readonly TaskDigestService $taskDigestService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $today    = now()->toDateString();
        $testUser = $this->option('test-user');

        $users = User::with(['telegramUser', 'organizations'])
            ->whereHas('telegramUser')
            ->get();

        foreach ($users as $user) {
            $meetings = $this->getTodayMeetings($user, $today);
            $groups   = $this->taskGrouper->groupForUser($user);

            $hasIssues = ($groups['focused'] ?? collect())->isNotEmpty()
                || $groups['today']->isNotEmpty()
                || $groups['current']->isNotEmpty();

            // Multi-org: one brief per (user, org) digest. If user is in 0 orgs,
            // send a single legacy brief with no digest section.
            $orgs = $user->organizations->isNotEmpty()
                ? $user->organizations->all()
                : [null];

            $sentAny = false;
            foreach ($orgs as $org) {
                $digest = $org instanceof Organization
                    ? $this->loadDigestForUser($user, $org)
                    : null;

                // For org-less users: only send if there's something to say.
                // For org members: at least one brief per org; show meetings/tasks only in the first.
                if ($org === null) {
                    if ($meetings->isEmpty() && ! $hasIssues) {
                        continue;
                    }
                    $this->sendBrief($user, $meetings, $groups, null, null, $testUser);
                    $sentAny = true;
                    continue;
                }

                // For multi-org: meetings/tasks only included in first brief to avoid repetition
                $isFirst = ! $sentAny;
                $briefMeetings = $isFirst ? $meetings : collect();
                $briefGroups = $isFirst ? $groups : ['focused' => collect(), 'today' => collect(), 'current' => collect()];
                $hasContent = $digest !== null || ($isFirst && (! $meetings->isEmpty() || $hasIssues));

                if (! $hasContent) {
                    continue;
                }

                $this->sendBrief($user, $briefMeetings, $briefGroups, $digest, $org, $testUser);
                $sentAny = true;
            }
        }

        return self::SUCCESS;
    }

    private function loadDigestForUser(User $user, Organization $org): ?array
    {
        try {
            return $this->taskDigestService->getCached(
                $user,
                $org,
                TaskDigest::PERIOD_DAILY,
                Carbon::now()->startOfDay(),
            );
        } catch (\Throwable $e) {
            Log::warning('SendMorningBrief: digest lookup failed', [
                'user_id' => $user->id,
                'organization_id' => $org->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function getTodayMeetings(User $user, string $today): Collection
    {
        $sourceIds = Source::where('user_id', $user->id)->pluck('id');

        return CalendarEvent::query()
            ->where(function ($q) use ($user, $sourceIds) {
                $q->whereHas('sources', fn($sq) => $sq->where('user_id', $user->id));
                if ($sourceIds->isNotEmpty()) {
                    $q->orWhereIn('source_id', $sourceIds);
                }
                $q->orWhereHas('profiles', fn($pq) => $pq->where('user_id', $user->id));
            })
            ->whereDate('starts_at', $today)
            ->with('generalAgenda')
            ->orderBy('starts_at')
            ->get();
    }

    private function sendBrief(User $user, Collection $meetings, array $groups, ?array $digest, ?Organization $org, ?string $testUser = null): void
    {
        $telegramUserId = $testUser ?? $user->telegramUser->telegram_user_id;
        $text = $this->formatMessage($meetings, $groups, $digest, $org, $user, (bool) $testUser);

        // DB-write first (transactional, returns control), then TG send (fire-and-forget).
        if ($digest !== null) {
            try {
                $user->notify(new ProgressNotification($digest));
            } catch (\Throwable $e) {
                Log::warning('SendMorningBrief: dashboard notification failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $telegram = new Api(config('telegram.bot_token'));
            $telegram->sendMessage([
                'chat_id'                  => $telegramUserId,
                'text'                     => $text,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SendMorningBrief: failed to send', [
                'user_id'          => $user->id,
                'telegram_user_id' => $telegramUserId,
                'error'            => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build a map [meeting_id => ['tips' => [...]]] from ALL of user's daily digests for today
     * across organizations. Meetings shown in the first brief belong to the user, not to a
     * specific org — so advice generated in any org's digest applies.
     */
    private function indexMeetingsAdvice(User $user, Carbon $date): array
    {
        $digests = \App\Models\TaskDigest::query()
            ->where('user_id', $user->id)
            ->where('period_type', 'daily')
            ->whereDate('period_start', $date->toDateString())
            ->get();

        $map = [];
        foreach ($digests as $digest) {
            $items = (array) (($digest->content['meetings_advice'] ?? []));
            foreach ($items as $item) {
                if (! is_array($item)) continue;
                $mid = (int) ($item['meeting_id'] ?? 0);
                if ($mid === 0) continue;
                $tips = array_values((array) ($item['tips'] ?? []));
                if (isset($map[$mid])) {
                    // Merge tips from multiple orgs' digests for same meeting
                    $map[$mid]['tips'] = array_unique(array_merge($map[$mid]['tips'], $tips));
                } else {
                    $map[$mid] = ['tips' => $tips];
                }
            }
        }
        return $map;
    }

    /**
     * Extract personal commitments from a meeting's agenda for the given user.
     *
     * Primary match path: `commitments_check[].issue_id → Issue::assignee_id == user.id`.
     * AgendaService stores `issue_id` for commitments it could match to existing tasks
     * (via CommitmentAnalyzer). Assignee linkage is a stable user-id reference — independent
     * of how the speaker's name was transcribed (e.g. "Борис" vs User.name "Boris").
     *
     * Fallback: name matching for commitments where `issue_id` is null (no issue matched).
     * Uses User.name plus any non-empty Profile.name as aliases.
     *
     * @return array<int, array{commitment:string,status:string,deadline:?string,question:?string,issue_id:?int}>
     */
    private function extractPersonalCommitments(CalendarEvent $meeting, User $user): array
    {
        $raw = $meeting->generalAgenda?->raw_json ?? [];
        $items = (array) ($raw['commitments_check'] ?? []);
        if (empty($items)) return [];

        // Batch-load assignee_id per issue_id for primary path
        $issueIds = collect($items)->pluck('issue_id')->filter()->unique()->all();
        $assigneeByIssue = [];
        if (! empty($issueIds)) {
            $assigneeByIssue = \App\Models\Issue::query()
                ->whereIn('id', $issueIds)
                ->pluck('assignee_id', 'id')
                ->all();
        }

        // Fallback name aliases
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
            $issueId = $c['issue_id'] ?? null;

            if ($issueId !== null && isset($assigneeByIssue[$issueId])) {
                // Primary: ID-based match
                $isMine = ((int) $assigneeByIssue[$issueId]) === (int) $user->id;
            } elseif (! empty($aliases)) {
                // Fallback: name-based match (only when no issue link exists)
                $person = mb_strtolower(trim((string) ($c['person'] ?? '')));
                $isMine = $person !== '' && in_array($person, $aliases, true);
            }

            if (! $isMine) continue;

            $out[] = [
                'commitment' => (string) ($c['commitment'] ?? ''),
                'status' => (string) ($c['status'] ?? 'open'),
                'deadline' => $c['deadline'] ?? null,
                'question' => $c['question'] ?? null,
                'issue_id' => $issueId,
            ];
        }
        return $out;
    }

    private function formatMessage(Collection $meetings, array $groups, ?array $digest, ?Organization $org, User $user, bool $testMode = false): string
    {
        $userName = $user->name;
        $lines = [];
        if ($testMode) {
            $lines[] = "🧪 <i>TEST — данные пользователя: {$userName}</i>";
            $lines[] = '';
        }
        $lines[] = '☀️ <b>Доброе утро!</b>';

        if ($digest !== null) {
            $this->appendDigestSection($lines, $digest);
        }

        if ($meetings->isNotEmpty()) {
            // Merge advice across ALL user's daily digests today (multi-org safe).
            $user->loadMissing('profiles');
            $adviceMap = $this->indexMeetingsAdvice($user, Carbon::today());

            $lines[] = '';
            $lines[] = '📅 <b>Встречи сегодня:</b>';
            foreach ($meetings as $meeting) {
                $start = Carbon::parse($meeting->starts_at)->format('H:i');
                $line  = "• {$start} — " . e($meeting->title);
                if ($meeting->ends_at) {
                    $duration = Carbon::parse($meeting->starts_at)->diffInMinutes(Carbon::parse($meeting->ends_at));
                    $line .= " <i>({$duration} мин)</i>";
                }
                if ($meeting->url) {
                    $line .= ' <a href="' . e($meeting->url) . '">🔗</a>';
                }
                $lines[] = $line;

                $goal = $meeting->generalAgenda?->raw_json['meeting_goal'] ?? null;
                if (is_string($goal) && $goal !== '') {
                    $lines[] = '    🎯 <i>' . e($goal) . '</i>';
                }

                // 💼 Personal commitments from agenda — show user's open obligations (Variant A)
                $commitments = $this->extractPersonalCommitments($meeting, $user);
                if (! empty($commitments)) {
                    foreach (array_slice($commitments, 0, 3) as $c) {
                        $marker = ($c['status'] ?? 'open') === 'done' ? '✓' : '•';
                        $cText = trim((string) $c['commitment']);
                        if ($cText === '') continue;
                        $extra = '';
                        if (! empty($c['deadline'])) $extra .= ' <i>(до ' . e((string) $c['deadline']) . ')</i>';
                        $lines[] = "    💼 {$marker} " . e($cText) . $extra;
                    }
                }

                // 🧠 AI-generated preparation tips (Variant B)
                $tips = $adviceMap[$meeting->id]['tips'] ?? [];
                foreach (array_slice($tips, 0, 3) as $tip) {
                    $tipText = trim((string) $tip);
                    if ($tipText === '') continue;
                    $lines[] = '    🧠 <i>' . e($tipText) . '</i>';
                }
            }
        }

        $focused = $groups['focused'] ?? collect();
        $hasIssues = $focused->isNotEmpty()
            || $groups['today']->isNotEmpty()
            || $groups['current']->isNotEmpty();

        if ($hasIssues) {
            $lines[] = '';
            $lines[] = '📋 <b>Задачи:</b>';

            if ($focused->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '⭐ <b>В фокусе:</b>';
                foreach ($focused as $issue) {
                    $lines[] = '• ' . $this->formatTaskLine($issue);
                }
            }

            if ($groups['today']->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '🟠 <b>Срочно — сегодня дедлайн:</b>';
                foreach ($groups['today'] as $issue) {
                    $lines[] = '• ' . $this->formatTaskLine($issue);
                }
            }

            if ($groups['current']->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '🔵 <b>Текущие задачи:</b>';
                foreach ($groups['current'] as $issue) {
                    $lines[] = '• ' . $this->formatTaskLine($issue);
                }
            }
        }

        return implode("\n", $lines);
    }

    private function appendDigestSection(array &$lines, array $digest): void
    {
        try {
            $progress = (array) ($digest['progress'] ?? []);
            $problems = (array) ($digest['problems'] ?? []);
            $priorities = (array) ($digest['priorities'] ?? []);
            $managerExtras = $digest['manager_extras'] ?? null;

            if (empty($progress) && empty($problems) && empty($priorities) && empty($managerExtras)) {
                return;
            }

            $lines[] = '';
            $lines[] = '🤖 <b>Helper-агент:</b>';

            if (! empty($progress)) {
                $lines[] = '';
                $lines[] = '<b>Прогресс:</b>';
                foreach ($progress as $item) {
                    $lines[] = '• ' . e((string) $item);
                }
            }

            if (! empty($problems)) {
                $lines[] = '';
                $lines[] = '<b>Проблемные места:</b>';
                foreach ($problems as $p) {
                    $severity = is_array($p) ? ($p['severity'] ?? 'M') : null;
                    $text = is_array($p) ? ($p['text'] ?? '') : (string) $p;
                    $prefix = $severity === 'H' ? '🔴 ' : ($severity === 'M' ? '🟡 ' : '🔵 ');
                    $lines[] = $prefix . e((string) $text);
                }
            }

            if (! empty($priorities)) {
                $lines[] = '';
                $lines[] = '<b>На следующий период:</b>';
                foreach ($priorities as $item) {
                    $lines[] = '• ' . e((string) $item);
                }
            }

            if (is_array($managerExtras) && ! empty($managerExtras['teams_breakdown'])) {
                $lines[] = '';
                $lines[] = '📊 <b>Команды организации:</b>';
                foreach ($managerExtras['teams_breakdown'] as $team) {
                    if (! is_array($team)) continue;
                    $teamName = e((string) ($team['team_name'] ?? '—'));
                    $done = (int) ($team['done'] ?? 0);
                    $inProgress = (int) ($team['in_progress'] ?? 0);
                    $overdue = (int) ($team['overdue'] ?? 0);
                    $lines[] = "• <b>{$teamName}:</b> done {$done}, in_progress {$inProgress}, overdue {$overdue}";
                }
            }
        } catch (\Throwable $e) {
            Log::warning('SendMorningBrief: digest section render failed, omitting', [
                'error' => $e->getMessage(),
            ]);
            // Don't break the rest of the brief
        }
    }

    private function formatTaskLine(Issue $issue): string
    {
        $frontendUrl = rtrim(config('app.frontend_url'), '/');
        $url  = $frontendUrl . '/dashboard/issues/' . $issue->id;
        $name = e($issue->name);
        $line = "<a href=\"{$url}\">{$name}</a>";

        $today = Carbon::today();
        $due   = $issue->due_date ? Carbon::parse($issue->due_date) : null;

        if ($due && $due->lt($today)) {
            $days = (int) $due->diffInDays($today);
            $line .= " <i>({$days}д просрочено)</i>";
        } elseif ($due && $due->gt($today)) {
            $line .= ' <i>(до ' . $due->format('d.m') . ')</i>';
        }

        return $line;
    }
}
