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

            $results = $action->kind === IssueNudge::KIND_MANAGER
                ? $this->sendManagerEscalation($issue, $daysStuck, $testTelegramUserId)
                : $this->sendExecutorNudge($issue, $action, $daysStuck, $testTelegramUserId);

            foreach ($results as $status) {
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
     * @return array<int, string>  array of status values for stats aggregation
     */
    private function sendExecutorNudge(Issue $issue, NextNudgeAction $action, int $daysStuck, ?string $testChatId): array
    {
        $assignee = $issue->assignee;
        if (! $assignee) {
            return []; // can't nudge nobody, don't record
        }

        $telegramId = $assignee->telegramUser?->telegram_user_id;
        if (! $telegramId) {
            $this->recordNudge(
                issue: $issue,
                kind: IssueNudge::KIND_EXECUTOR,
                recipient: $assignee,
                attempt: $action->attempt,
                templateKey: $action->templateKey,
                daysStuck: $daysStuck,
                status: IssueNudge::STATUS_SKIPPED,
                payload: ['reason' => 'no_telegram_user'],
            );
            return [IssueNudge::STATUS_SKIPPED];
        }

        $text = $action->templateKey === IssueNudge::TEMPLATE_EXEC_2
            ? $this->formatExec2($issue, $daysStuck)
            : $this->formatExec1($issue, $daysStuck);

        $chatId = $testChatId ?? (string) $telegramId;

        try {
            $this->sendTelegram($chatId, $text);

            $this->recordNudge(
                issue: $issue,
                kind: IssueNudge::KIND_EXECUTOR,
                recipient: $assignee,
                attempt: $action->attempt,
                templateKey: $action->templateKey,
                daysStuck: $daysStuck,
                status: IssueNudge::STATUS_SENT,
                payload: $testChatId ? ['test_redirect' => true] : [],
            );
            return [IssueNudge::STATUS_SENT];
        } catch (\Throwable $e) {
            $sanitized = TelegramErrorSanitizer::sanitize($e->getMessage());
            Log::warning('StuckIssueNudgeService: executor nudge send failed', [
                'issue_id'    => $issue->id,
                'assignee_id' => $assignee->id,
                'attempt'     => $action->attempt,
                'error'       => $sanitized,
            ]);

            $this->recordNudge(
                issue: $issue,
                kind: IssueNudge::KIND_EXECUTOR,
                recipient: $assignee,
                attempt: $action->attempt,
                templateKey: $action->templateKey,
                daysStuck: $daysStuck,
                status: IssueNudge::STATUS_FAILED,
                payload: $testChatId ? ['test_redirect' => true] : [],
                error: $sanitized,
            );
            return [IssueNudge::STATUS_FAILED];
        }
    }

    /**
     * @return array<int, string>  array of status values per manager attempt
     */
    private function sendManagerEscalation(Issue $issue, int $daysStuck, ?string $testChatId): array
    {
        $assignee = $issue->assignee;
        if (! $assignee) {
            return [];
        }

        $managers = $this->resolveManagers($issue);
        if ($managers->isEmpty()) {
            Log::warning('StuckIssueNudgeService: escalation skipped — no managers in org', [
                'issue_id'        => $issue->id,
                'organization_id' => $issue->organization_id,
            ]);
            return []; // silent skip per spec — no row written
        }

        $text = $this->formatEscalation($issue, $daysStuck, $assignee);
        $results = [];

        foreach ($managers as $manager) {
            $telegramId = $manager->telegramUser?->telegram_user_id;
            if (! $telegramId) {
                $this->recordNudge(
                    issue: $issue,
                    kind: IssueNudge::KIND_MANAGER,
                    recipient: $manager,
                    attempt: 1,
                    templateKey: IssueNudge::TEMPLATE_ESCALATION,
                    daysStuck: $daysStuck,
                    status: IssueNudge::STATUS_SKIPPED,
                    payload: ['reason' => 'no_telegram_user'],
                );
                $results[] = IssueNudge::STATUS_SKIPPED;
                continue;
            }

            $chatId = $testChatId ?? (string) $telegramId;

            try {
                $this->sendTelegram($chatId, $text);

                $this->recordNudge(
                    issue: $issue,
                    kind: IssueNudge::KIND_MANAGER,
                    recipient: $manager,
                    attempt: 1,
                    templateKey: IssueNudge::TEMPLATE_ESCALATION,
                    daysStuck: $daysStuck,
                    status: IssueNudge::STATUS_SENT,
                    payload: $testChatId ? ['test_redirect' => true] : [],
                );
                $results[] = IssueNudge::STATUS_SENT;
            } catch (\Throwable $e) {
                $sanitized = TelegramErrorSanitizer::sanitize($e->getMessage());
                Log::warning('StuckIssueNudgeService: manager escalation send failed', [
                    'issue_id'   => $issue->id,
                    'manager_id' => $manager->id,
                    'error'      => $sanitized,
                ]);

                $this->recordNudge(
                    issue: $issue,
                    kind: IssueNudge::KIND_MANAGER,
                    recipient: $manager,
                    attempt: 1,
                    templateKey: IssueNudge::TEMPLATE_ESCALATION,
                    daysStuck: $daysStuck,
                    status: IssueNudge::STATUS_FAILED,
                    payload: $testChatId ? ['test_redirect' => true] : [],
                    error: $sanitized,
                );
                $results[] = IssueNudge::STATUS_FAILED;
            }
        }

        return $results;
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

    private function formatExec1(Issue $issue, int $daysStuck): string
    {
        return implode("\n", [
            '👋 Привет!',
            '',
            'Как продвигается задача <a href="' . e($this->issueUrl($issue)) . '">«' . e($issue->name) . '»</a>?',
            'Уже ' . $daysStuck . ' дня без движения — может, что-то блокирует?',
        ]);
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
}
