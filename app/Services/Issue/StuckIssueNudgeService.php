<?php

namespace App\Services\Issue;

use App\Models\Issue;
use App\Models\IssueNudge;
use App\Models\User;
use App\Support\Telegram\TelegramErrorSanitizer;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

/**
 * Daily multi-step nudge orchestrator for stuck issues.
 *
 * Cadence: 2 / 4 / 6 days of inactivity.
 *   day 2  → nudge #1 to assignee   (friendly tone)
 *   day 4  → nudge #2 to assignee   (concrete, suggests action)
 *   day 6  → escalation to all org managers
 *
 * "Activity" = MAX(issue.updated_at, latest issue_comments.created_at).
 * Any activity resets the series — next nudge starts over at #1.
 *
 * Idempotency via per-Issue series tracking: series = nudges with
 * created_at > last_movement_at. Daily reruns are safe (series-check skips
 * already-sent attempts).
 *
 * Batching: within a single run, pending nudges are grouped per recipient and
 * merged into ONE Telegram message per (assignee, stage) and ONE per manager —
 * a recipient with many stuck issues no longer gets a wall of messages. The
 * per-Issue cadence is unchanged: one IssueNudge row is still written per issue,
 * so attempt tracking stays granular. A batch send that fails marks every issue
 * in it as failed (the attempt is "burned" together).
 *
 * NOT applicable to pipeline rules in CLAUDE.md (no event dispatch,
 * no LLM, not invoked from a queued listener).
 */
class StuckIssueNudgeService
{
    public const DAYS_EXEC_1     = 2;
    public const DAYS_EXEC_2     = 4;
    public const DAYS_ESCALATION = 6;

    /** Look-back window for batch-prefetching nudge series (covers all cadences + buffer). */
    private const SERIES_WINDOW_DAYS = 8;

    private ?Api $telegram = null;

    public function __construct() {}

    /**
     * @return array{sent: int, skipped: int, failed: int}
     */
    public function run(?string $testTelegramUserId = null): array
    {
        $stats = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        $candidates = $this->findCandidates();
        if ($candidates->isEmpty()) {
            return $stats;
        }

        $nudgesByIssue = $this->batchPrefetchNudges($candidates);

        // Phase 1 — bucket pending actions per recipient; nothing is sent yet.
        //   $execBuckets:       [assignee_id][template_key] => ['assignee' => User, 'items' => [...]]
        //   $escalationBuckets: [manager_id]                => ['manager'  => User, 'items' => [...]]
        // Executor buckets are keyed by template too, so each stage (exec_1 / exec_2)
        // becomes its own message — we merge only within a stage.
        $execBuckets = [];
        $escalationBuckets = [];

        foreach ($candidates as $issue) {
            $lastMovement = $this->computeLastMovement($issue);
            // Carbon 3: $past->diffInDays($now) is positive; reverse args returns negative.
            $daysStuck = (int) $lastMovement->diffInDays(Carbon::now());

            if ($daysStuck < self::DAYS_EXEC_1) {
                continue;
            }

            $series = ($nudgesByIssue[$issue->id] ?? collect())
                ->filter(fn (IssueNudge $n) => $n->created_at->gt($lastMovement));

            $action = $this->determineNextAction($daysStuck, $series);
            if ($action === null) {
                continue;
            }

            $assignee = $issue->assignee;
            if (! $assignee) {
                continue; // can't nudge nobody, don't record
            }

            if ($action->kind === IssueNudge::KIND_MANAGER) {
                $managers = $this->resolveManagers($issue);
                if ($managers->isEmpty()) {
                    Log::warning('StuckIssueNudgeService: escalation skipped — no managers in org', [
                        'issue_id'        => $issue->id,
                        'organization_id' => $issue->organization_id,
                    ]);
                    continue; // silent skip per spec — no row written
                }

                foreach ($managers as $manager) {
                    $escalationBuckets[$manager->id] ??= ['manager' => $manager, 'items' => []];
                    $escalationBuckets[$manager->id]['items'][] = [
                        'issue'     => $issue,
                        'assignee'  => $assignee,
                        'daysStuck' => $daysStuck,
                    ];
                }

                continue;
            }

            $execBuckets[$assignee->id][$action->templateKey] ??= ['assignee' => $assignee, 'items' => []];
            $execBuckets[$assignee->id][$action->templateKey]['items'][] = [
                'issue'     => $issue,
                'action'    => $action,
                'daysStuck' => $daysStuck,
            ];
        }

        // Phase 2a — one message per (assignee, stage).
        foreach ($execBuckets as $byTemplate) {
            foreach ($byTemplate as $bucket) {
                foreach ($this->sendExecutorBatch($bucket['assignee'], $bucket['items'], $testTelegramUserId) as $status) {
                    $stats[$status] = ($stats[$status] ?? 0) + 1;
                }
            }
        }

        // Phase 2b — one message per manager (issues grouped by assignee inside).
        foreach ($escalationBuckets as $bucket) {
            foreach ($this->sendManagerBatch($bucket['manager'], $bucket['items'], $testTelegramUserId) as $status) {
                $stats[$status] = ($stats[$status] ?? 0) + 1;
            }
        }

        return $stats;
    }

    /**
     * @return Collection<int, Issue>
     */
    private function findCandidates(): Collection
    {
        return Issue::query()
            ->activeForNudging()
            ->whereNotNull('assignee_id')
            ->withMax('allComments as latest_comment_at', 'created_at')
            ->with(['assignee.telegramUser'])
            ->withoutTrashed()
            ->orderBy('id') // deterministic candidate order → stable bullet/group order in batched messages
            ->get();
    }

    /**
     * @param  Collection<int, Issue>  $issues
     * @return Collection<int, Collection<int, IssueNudge>>  Keyed by issue_id
     */
    private function batchPrefetchNudges(Collection $issues): Collection
    {
        if ($issues->isEmpty()) {
            return collect();
        }

        return IssueNudge::query()
            ->whereIn('issue_id', $issues->pluck('id'))
            ->where('created_at', '>', Carbon::now()->subDays(self::SERIES_WINDOW_DAYS))
            ->get()
            ->groupBy('issue_id');
    }

    private function computeLastMovement(Issue $issue): Carbon
    {
        $updated = Carbon::parse($issue->updated_at);
        $latest = $issue->latest_comment_at ? Carbon::parse($issue->latest_comment_at) : null;

        return $latest && $latest->gt($updated) ? $latest : $updated;
    }

    /**
     * @param  Collection<int, IssueNudge>  $series
     */
    private function determineNextAction(int $daysStuck, Collection $series): ?NextNudgeAction
    {
        $hasExec1 = $series->contains(
            fn (IssueNudge $n) => $n->kind === IssueNudge::KIND_EXECUTOR && $n->attempt_no === 1
        );
        $hasExec2 = $series->contains(
            fn (IssueNudge $n) => $n->kind === IssueNudge::KIND_EXECUTOR && $n->attempt_no === 2
        );
        $hasEscalated = $series->contains(
            fn (IssueNudge $n) => $n->kind === IssueNudge::KIND_MANAGER
        );

        if ($daysStuck >= self::DAYS_ESCALATION && $hasExec1 && $hasExec2 && ! $hasEscalated) {
            return NextNudgeAction::escalation();
        }
        if ($daysStuck >= self::DAYS_EXEC_2 && $hasExec1 && ! $hasExec2) {
            return NextNudgeAction::exec2();
        }
        if ($daysStuck >= self::DAYS_EXEC_1 && ! $hasExec1) {
            return NextNudgeAction::exec1();
        }

        return null;
    }

    /**
     * Send one batched executor message (all items share the same stage/template)
     * and write one IssueNudge row per issue.
     *
     * @param  array<int, array{issue: Issue, action: NextNudgeAction, daysStuck: int}>  $items
     * @return array<int, string>  one status value per issue, for stats aggregation
     */
    private function sendExecutorBatch(User $assignee, array $items, ?string $testChatId): array
    {
        $templateKey = $items[0]['action']->templateKey;

        $telegramId = $assignee->telegramUser?->telegram_user_id;
        if (! $telegramId) {
            $this->recordExecutorRows($items, $assignee, IssueNudge::STATUS_SKIPPED, ['reason' => 'no_telegram_user']);

            return array_fill(0, count($items), IssueNudge::STATUS_SKIPPED);
        }

        $text = $templateKey === IssueNudge::TEMPLATE_EXEC_2
            ? $this->formatExec2Batch($items)
            : $this->formatExec1Batch($items);

        $chatId = $testChatId ?? (string) $telegramId;
        $redirectPayload = $testChatId ? ['test_redirect' => true] : [];

        try {
            $this->sendTelegram($chatId, $text);
        } catch (\Throwable $e) {
            $sanitized = TelegramErrorSanitizer::sanitize($e->getMessage());
            Log::warning('StuckIssueNudgeService: executor nudge send failed', [
                'assignee_id' => $assignee->id,
                'issue_ids'   => array_map(fn ($i) => $i['issue']->id, $items),
                'template'    => $templateKey,
                'error'       => $sanitized,
            ]);

            $this->recordExecutorRows($items, $assignee, IssueNudge::STATUS_FAILED, $redirectPayload, $sanitized);

            return array_fill(0, count($items), IssueNudge::STATUS_FAILED);
        }

        $this->recordExecutorRows($items, $assignee, IssueNudge::STATUS_SENT, $redirectPayload);

        return array_fill(0, count($items), IssueNudge::STATUS_SENT);
    }

    /**
     * Send one batched escalation message to a manager (issues grouped by assignee)
     * and write one IssueNudge row per issue.
     *
     * @param  array<int, array{issue: Issue, assignee: User, daysStuck: int}>  $items
     * @return array<int, string>  one status value per issue, for stats aggregation
     */
    private function sendManagerBatch(User $manager, array $items, ?string $testChatId): array
    {
        $telegramId = $manager->telegramUser?->telegram_user_id;
        if (! $telegramId) {
            $this->recordManagerRows($items, $manager, IssueNudge::STATUS_SKIPPED, ['reason' => 'no_telegram_user']);

            return array_fill(0, count($items), IssueNudge::STATUS_SKIPPED);
        }

        $text = $this->formatEscalationBatch($items);
        $chatId = $testChatId ?? (string) $telegramId;
        $redirectPayload = $testChatId ? ['test_redirect' => true] : [];

        try {
            $this->sendTelegram($chatId, $text);
        } catch (\Throwable $e) {
            $sanitized = TelegramErrorSanitizer::sanitize($e->getMessage());
            Log::warning('StuckIssueNudgeService: manager escalation send failed', [
                'manager_id' => $manager->id,
                'issue_ids'  => array_map(fn ($i) => $i['issue']->id, $items),
                'error'      => $sanitized,
            ]);

            $this->recordManagerRows($items, $manager, IssueNudge::STATUS_FAILED, $redirectPayload, $sanitized);

            return array_fill(0, count($items), IssueNudge::STATUS_FAILED);
        }

        $this->recordManagerRows($items, $manager, IssueNudge::STATUS_SENT, $redirectPayload);

        return array_fill(0, count($items), IssueNudge::STATUS_SENT);
    }

    /**
     * @param  array<int, array{issue: Issue, action: NextNudgeAction, daysStuck: int}>  $items
     */
    private function recordExecutorRows(array $items, User $assignee, string $status, array $payload = [], ?string $error = null): void
    {
        foreach ($items as $item) {
            $this->recordNudge(
                issue: $item['issue'],
                kind: IssueNudge::KIND_EXECUTOR,
                recipient: $assignee,
                attempt: $item['action']->attempt,
                templateKey: $item['action']->templateKey,
                daysStuck: $item['daysStuck'],
                status: $status,
                payload: $payload,
                error: $error,
            );
        }
    }

    /**
     * @param  array<int, array{issue: Issue, assignee: User, daysStuck: int}>  $items
     */
    private function recordManagerRows(array $items, User $manager, string $status, array $payload = [], ?string $error = null): void
    {
        foreach ($items as $item) {
            $this->recordNudge(
                issue: $item['issue'],
                kind: IssueNudge::KIND_MANAGER,
                recipient: $manager,
                attempt: 1,
                templateKey: IssueNudge::TEMPLATE_ESCALATION,
                daysStuck: $item['daysStuck'],
                status: $status,
                payload: $payload,
                error: $error,
            );
        }
    }

    /**
     * Resolves all managers in the Issue's organization, excluding the assignee.
     * Uses issues.organization_id as single source of truth (tenant-safe).
     * Returns empty collection if organization_id is null — silent skip per spec.
     *
     * @return Collection<int, User>
     */
    private function resolveManagers(Issue $issue): Collection
    {
        if (! $issue->organization_id) {
            return collect();
        }

        return User::query()
            ->with('telegramUser')
            ->whereHas('organizations', fn ($q) => $q
                ->where('organizations.id', $issue->organization_id)
                ->where('organization_user.role', 'manager')
            )
            ->where('id', '!=', $issue->assignee_id)
            ->orderBy('id')
            ->get();
    }

    private function sendTelegram(string $chatId, string $text): void
    {
        $this->telegram()->sendMessage([
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ]);
    }

    private function telegram(): Api
    {
        return $this->telegram ??= new Api(config('telegram.bot_token'));
    }

    private function recordNudge(
        Issue $issue,
        string $kind,
        ?User $recipient,
        int $attempt,
        string $templateKey,
        int $daysStuck,
        string $status,
        array $payload = [],
        ?string $error = null,
    ): IssueNudge {
        return IssueNudge::create([
            'issue_id'          => $issue->id,
            'kind'              => $kind,
            'recipient_user_id' => $recipient?->id,
            'attempt_no'        => $attempt,
            'template_key'      => $templateKey,
            'days_stuck'        => $daysStuck,
            'sent_at'           => $status === IssueNudge::STATUS_SENT ? now() : null,
            'status'            => $status,
            'payload'           => $payload ?: null,
            'error'             => $error,
        ]);
    }

    private function issueUrl(Issue $issue): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/dashboard/issues/' . $issue->id;
    }

    private function issueBullet(Issue $issue, int $daysStuck, string $unit): string
    {
        return '• <a href="' . e($this->issueUrl($issue)) . '">«' . e($issue->name) . '»</a> — ' . $daysStuck . ' ' . $unit;
    }

    private function formatExec1(Issue $issue, int $daysStuck): string
    {
        return implode("\n", [
            '👋 Привет!',
            '',
            'Как продвигается задача <a href="' . e($this->issueUrl($issue)) . '">«' . e($issue->name) . '»</a>?',
            'Уже ' . $daysStuck . ' дня без движения — может, что-то блокирует?',
        ]);
    }

    /**
     * @param  array<int, array{issue: Issue, action: NextNudgeAction, daysStuck: int}>  $items
     */
    private function formatExec1Batch(array $items): string
    {
        if (count($items) === 1) {
            return $this->formatExec1($items[0]['issue'], $items[0]['daysStuck']);
        }

        $lines = [
            '👋 Привет!',
            '',
            'Несколько задач без движения — может, что-то блокирует?',
        ];
        foreach ($items as $item) {
            $lines[] = $this->issueBullet($item['issue'], $item['daysStuck'], 'дня');
        }

        return implode("\n", $lines);
    }

    private function formatExec2(Issue $issue, int $daysStuck): string
    {
        return implode("\n", [
            '🔔 Задача <a href="' . e($this->issueUrl($issue)) . '">«' . e($issue->name) . '»</a> не двигается ' . $daysStuck . ' дня.',
            '',
            'Нужна помощь? Если задача уже не актуальна — закрой её.',
            'Если кому-то лучше передать — поменяй исполнителя.',
        ]);
    }

    /**
     * @param  array<int, array{issue: Issue, action: NextNudgeAction, daysStuck: int}>  $items
     */
    private function formatExec2Batch(array $items): string
    {
        if (count($items) === 1) {
            return $this->formatExec2($items[0]['issue'], $items[0]['daysStuck']);
        }

        $lines = [
            '🔔 Несколько задач не двигаются:',
            '',
        ];
        foreach ($items as $item) {
            $lines[] = $this->issueBullet($item['issue'], $item['daysStuck'], 'дня');
        }
        $lines[] = '';
        $lines[] = 'Нужна помощь? Если задача уже не актуальна — закрой её.';
        $lines[] = 'Если кому-то лучше передать — поменяй исполнителя.';

        return implode("\n", $lines);
    }

    private function formatEscalation(Issue $issue, int $daysStuck, User $assignee): string
    {
        return implode("\n", [
            '🟠 <b>Зависшая задача требует внимания</b>',
            '',
            'У <b>' . e($assignee->name) . '</b> задача <a href="' . e($this->issueUrl($issue)) . '">«' . e($issue->name) . '»</a> без движения ' . $daysStuck . ' дней.',
            'Два напоминания исполнителю не сработали.',
            '',
            'Возможно, стоит обсудить блокеры или переназначить.',
        ]);
    }

    /**
     * @param  array<int, array{issue: Issue, assignee: User, daysStuck: int}>  $items
     */
    private function formatEscalationBatch(array $items): string
    {
        if (count($items) === 1) {
            return $this->formatEscalation($items[0]['issue'], $items[0]['daysStuck'], $items[0]['assignee']);
        }

        // Group issues by assignee, preserving first-seen order.
        $byAssignee = [];
        foreach ($items as $item) {
            $aid = $item['assignee']->id;
            $byAssignee[$aid]['assignee'] ??= $item['assignee'];
            $byAssignee[$aid]['issues'][] = $item;
        }

        $lines = [
            '🟠 <b>Зависшие задачи требуют внимания</b>',
            '',
            'Напоминания исполнителям не сработали:',
        ];
        foreach ($byAssignee as $group) {
            $lines[] = '';
            $lines[] = '<b>' . e($group['assignee']->name) . '</b>:';
            foreach ($group['issues'] as $item) {
                $lines[] = $this->issueBullet($item['issue'], $item['daysStuck'], 'дней');
            }
        }
        $lines[] = '';
        $lines[] = 'Возможно, стоит обсудить блокеры или переназначить.';

        return implode("\n", $lines);
    }
}
