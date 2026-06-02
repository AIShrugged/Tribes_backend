<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\IssueNudge;
use App\Models\Organization;
use App\Models\Team;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Issue\StuckIssueNudgeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Message;
use Tests\TestCase;

class StuckIssueNudgeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────

    private function makeUserWithTg(?string $tgId = null, string $name = 'Alice'): User
    {
        $user = User::factory()->create(['name' => $name]);
        TelegramUser::create([
            'user_id'          => $user->id,
            // telegram_user_id is bigint in DB — must be numeric. Default derives from user.id.
            'telegram_user_id' => $tgId ?? (string) (1_000_000 + $user->id),
        ]);

        return $user;
    }

    private function makeOrgWithManager(?User $manager = null): array
    {
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.uniqid()]);
        if ($manager) {
            $org->users()->attach($manager->id, ['role' => 'manager']);
        }

        return [$org, $manager];
    }

    private function makeStuckIssue(User $assignee, ?Organization $org, int $daysAgo, string $name = 'Подключить аналитику'): Issue
    {
        $issue = Issue::create([
            'name'            => $name,
            'status'          => 'open',
            'assignee_id'     => $assignee->id,
            'organization_id' => $org?->id,
            'type'            => 'organization',
        ]);

        // Force updated_at to the past — bypasses model events.
        Issue::query()->where('id', $issue->id)->update([
            'updated_at' => Carbon::now()->subDays($daysAgo),
        ]);

        return $issue->fresh();
    }

    private function addNudge(Issue $issue, string $kind, int $attempt, string $template, int $daysAgo = 0): IssueNudge
    {
        $nudge = IssueNudge::create([
            'issue_id'          => $issue->id,
            'kind'              => $kind,
            'recipient_user_id' => $issue->assignee_id,
            'attempt_no'        => $attempt,
            'template_key'      => $template,
            'days_stuck'        => 2,
            'sent_at'           => Carbon::now()->subDays($daysAgo),
            'status'            => IssueNudge::STATUS_SENT,
        ]);

        // Force created_at to the past (Eloquent overrides on insert; do explicit update).
        IssueNudge::query()->where('id', $nudge->id)->update([
            'created_at' => Carbon::now()->subDays($daysAgo),
        ]);

        return $nudge->fresh();
    }

    private function injectTelegramMock(StuckIssueNudgeService $service, $mock): void
    {
        $ref = new \ReflectionClass($service);
        $prop = $ref->getProperty('telegram');
        $prop->setAccessible(true);
        $prop->setValue($service, $mock);
    }

    private function makeService(?Api $telegram = null): StuckIssueNudgeService
    {
        $service = new StuckIssueNudgeService();
        if ($telegram) {
            $this->injectTelegramMock($service, $telegram);
        }
        return $service;
    }

    // ────────────────────────────────────────────────────────────────────
    // Tests
    // ────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_sends_nudge_1_to_assignee_after_2_days(): void
    {
        $assignee = $this->makeUserWithTg();
        $issue = $this->makeStuckIssue($assignee, null, daysAgo: 3);

        $tg = Mockery::mock(Api::class);
        $tg->shouldReceive('sendMessage')->once()->andReturn(new Message([]));

        $stats = $this->makeService($tg)->run();

        $this->assertSame(['sent' => 1, 'skipped' => 0, 'failed' => 0], $stats);
        $this->assertSame(1, $issue->nudges()->count());
        $n = $issue->nudges()->first();
        $this->assertSame(IssueNudge::KIND_EXECUTOR, $n->kind);
        $this->assertSame(1, $n->attempt_no);
        $this->assertSame(IssueNudge::TEMPLATE_EXEC_1, $n->template_key);
        $this->assertSame(IssueNudge::STATUS_SENT, $n->status);
        $this->assertSame($assignee->id, $n->recipient_user_id);
    }

    #[Test]
    public function it_transitions_to_nudge_2_after_4_days_when_nudge_1_already_sent(): void
    {
        $assignee = $this->makeUserWithTg();
        $issue = $this->makeStuckIssue($assignee, null, daysAgo: 4);
        $this->addNudge($issue, IssueNudge::KIND_EXECUTOR, 1, IssueNudge::TEMPLATE_EXEC_1, daysAgo: 2);

        $tg = Mockery::mock(Api::class);
        $tg->shouldReceive('sendMessage')->once()->andReturn(new Message([]));

        $this->makeService($tg)->run();

        $nudges = $issue->nudges()->orderBy('attempt_no')->get();
        $this->assertCount(2, $nudges);
        $this->assertSame(2, $nudges[1]->attempt_no);
        $this->assertSame(IssueNudge::TEMPLATE_EXEC_2, $nudges[1]->template_key);
    }

    #[Test]
    public function it_escalates_to_all_managers_after_6_days(): void
    {
        $assignee = $this->makeUserWithTg('5001', 'Alice');
        $manager1 = $this->makeUserWithTg('5002', 'Mary');
        $manager2 = $this->makeUserWithTg('5003', 'Mike');
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $org->users()->attach($manager1->id, ['role' => 'manager']);
        $org->users()->attach($manager2->id, ['role' => 'manager']);

        $issue = $this->makeStuckIssue($assignee, $org, daysAgo: 7);
        $this->addNudge($issue, IssueNudge::KIND_EXECUTOR, 1, IssueNudge::TEMPLATE_EXEC_1, daysAgo: 5);
        $this->addNudge($issue, IssueNudge::KIND_EXECUTOR, 2, IssueNudge::TEMPLATE_EXEC_2, daysAgo: 3);

        $tg = Mockery::mock(Api::class);
        $tg->shouldReceive('sendMessage')->twice()->andReturn(new Message([]));

        $this->makeService($tg)->run();

        $managerNudges = $issue->nudges()->where('kind', IssueNudge::KIND_MANAGER)->get();
        $this->assertCount(2, $managerNudges);
        $recipientIds = $managerNudges->pluck('recipient_user_id')->sort()->values()->all();
        $this->assertSame([$manager1->id, $manager2->id], $recipientIds);
        foreach ($managerNudges as $n) {
            $this->assertSame(IssueNudge::TEMPLATE_ESCALATION, $n->template_key);
            $this->assertSame(IssueNudge::STATUS_SENT, $n->status);
            $this->assertSame(7, $n->days_stuck);
        }
    }

    #[Test]
    public function recent_comment_resets_the_series(): void
    {
        $assignee = $this->makeUserWithTg();
        $issue = $this->makeStuckIssue($assignee, null, daysAgo: 5);
        $this->addNudge($issue, IssueNudge::KIND_EXECUTOR, 1, IssueNudge::TEMPLATE_EXEC_1, daysAgo: 3);

        // Comment 1 day ago — should reset series. daysStuck=1 < DAYS_EXEC_1=2, so no nudge.
        $comment = IssueComment::create([
            'issue_id' => $issue->id,
            'user_id'  => $assignee->id,
            'content'  => 'Работаю над задачей, есть блокер',
        ]);
        IssueComment::query()->where('id', $comment->id)->update([
            'created_at' => Carbon::now()->subDay(),
        ]);

        $tg = Mockery::mock(Api::class);
        $tg->shouldNotReceive('sendMessage');

        $stats = $this->makeService($tg)->run();

        $this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 0], $stats);
        // Only the seed nudge — no new one created.
        $this->assertSame(1, $issue->nudges()->count());
    }

    #[Test]
    public function double_cron_run_does_not_duplicate(): void
    {
        $assignee = $this->makeUserWithTg();
        $issue = $this->makeStuckIssue($assignee, null, daysAgo: 3);

        $tg = Mockery::mock(Api::class);
        $tg->shouldReceive('sendMessage')->once()->andReturn(new Message([]));

        $service = $this->makeService($tg);
        $service->run(); // sends #1
        $service->run(); // should be no-op (#1 already in series)

        $this->assertSame(1, $issue->nudges()->count());
    }

    #[Test]
    public function escalation_silently_skips_when_no_managers(): void
    {
        $assignee = $this->makeUserWithTg();
        // org exists but no managers attached
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $issue = $this->makeStuckIssue($assignee, $org, daysAgo: 7);
        $this->addNudge($issue, IssueNudge::KIND_EXECUTOR, 1, IssueNudge::TEMPLATE_EXEC_1, daysAgo: 5);
        $this->addNudge($issue, IssueNudge::KIND_EXECUTOR, 2, IssueNudge::TEMPLATE_EXEC_2, daysAgo: 3);

        $tg = Mockery::mock(Api::class);
        $tg->shouldNotReceive('sendMessage');

        $stats = $this->makeService($tg)->run();

        $this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 0], $stats);
        // No manager-kind row written (silent skip per spec)
        $this->assertSame(0, $issue->nudges()->where('kind', IssueNudge::KIND_MANAGER)->count());
    }

    #[Test]
    public function assignee_without_telegram_yields_skipped_row(): void
    {
        $assignee = User::factory()->create(['name' => 'Bob']); // NO TelegramUser
        $issue = $this->makeStuckIssue($assignee, null, daysAgo: 3);

        $tg = Mockery::mock(Api::class);
        $tg->shouldNotReceive('sendMessage');

        $stats = $this->makeService($tg)->run();

        $this->assertSame(['sent' => 0, 'skipped' => 1, 'failed' => 0], $stats);
        $n = $issue->nudges()->first();
        $this->assertSame(IssueNudge::STATUS_SKIPPED, $n->status);
        $this->assertSame('no_telegram_user', $n->payload['reason'] ?? null);
    }

    #[Test]
    public function telegram_failure_records_sanitized_error(): void
    {
        $assignee = $this->makeUserWithTg();
        $issue = $this->makeStuckIssue($assignee, null, daysAgo: 3);

        $tg = Mockery::mock(Api::class);
        $tg->shouldReceive('sendMessage')->once()->andThrow(new \RuntimeException(
            'Client error: `POST https://api.telegram.org/bot12345:ABCdef-_XYZ/sendMessage` resulted in 429 Too Many Requests'
        ));

        $stats = $this->makeService($tg)->run();

        $this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 1], $stats);
        $n = $issue->nudges()->first();
        $this->assertSame(IssueNudge::STATUS_FAILED, $n->status);
        $this->assertStringNotContainsString('bot12345:ABCdef-_XYZ', $n->error);
        $this->assertStringContainsString('bot***', $n->error);
    }

    #[Test]
    public function escalation_message_escapes_html_in_assignee_name(): void
    {
        $assignee = $this->makeUserWithTg('6001', '<script>alert(1)</script>');
        $manager = $this->makeUserWithTg('6002', 'Mary');
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $org->users()->attach($manager->id, ['role' => 'manager']);

        $issue = $this->makeStuckIssue($assignee, $org, daysAgo: 7, name: '<b>InjectedTitle</b>');
        $this->addNudge($issue, IssueNudge::KIND_EXECUTOR, 1, IssueNudge::TEMPLATE_EXEC_1, daysAgo: 5);
        $this->addNudge($issue, IssueNudge::KIND_EXECUTOR, 2, IssueNudge::TEMPLATE_EXEC_2, daysAgo: 3);

        $sentText = null;
        $tg = Mockery::mock(Api::class);
        $tg->shouldReceive('sendMessage')->once()->andReturnUsing(function (array $params) use (&$sentText) {
            $sentText = $params['text'];
            return new Message([]);
        });

        $this->makeService($tg)->run();

        $this->assertNotNull($sentText);
        // Raw injection must not survive
        $this->assertStringNotContainsString('<script>alert(1)</script>', $sentText);
        $this->assertStringNotContainsString('<b>InjectedTitle</b>', $sentText);
        // Escaped form must be present
        $this->assertStringContainsString('&lt;script&gt;', $sentText);
        $this->assertStringContainsString('&lt;b&gt;InjectedTitle&lt;/b&gt;', $sentText);
    }

    // ────────────────────────────────────────────────────────────────────
    // Batching — one message per recipient/stage
    // ────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_merges_multiple_exec2_issues_for_one_assignee_into_one_message(): void
    {
        $assignee = $this->makeUserWithTg('7001', 'Alice');

        $titles = ['Review mechanisms', 'Не создаются задачи', 'Поправить аттачи'];
        $issues = [];
        foreach ($titles as $title) {
            $issue = $this->makeStuckIssue($assignee, null, daysAgo: 4, name: $title);
            $this->addNudge($issue, IssueNudge::KIND_EXECUTOR, 1, IssueNudge::TEMPLATE_EXEC_1, daysAgo: 2);
            $issues[] = $issue;
        }

        $sentText = null;
        $tg = Mockery::mock(Api::class);
        // Three stuck issues for one assignee → exactly ONE message.
        $tg->shouldReceive('sendMessage')->once()->andReturnUsing(function (array $params) use (&$sentText) {
            $sentText = $params['text'];
            return new Message([]);
        });

        $stats = $this->makeService($tg)->run();

        $this->assertSame(['sent' => 3, 'skipped' => 0, 'failed' => 0], $stats);
        $this->assertNotNull($sentText);
        $this->assertStringContainsString('🔔 Несколько задач не двигаются:', $sentText);
        foreach ($titles as $title) {
            $this->assertStringContainsString('«'.$title.'»', $sentText);
        }
        // One exec_2 row written per issue (cadence stays granular).
        foreach ($issues as $issue) {
            $this->assertSame(1, $issue->nudges()->where('template_key', IssueNudge::TEMPLATE_EXEC_2)->count());
        }
    }

    #[Test]
    public function it_keeps_exec1_and_exec2_in_separate_messages_for_one_assignee(): void
    {
        $assignee = $this->makeUserWithTg('7101', 'Bob');

        // Two fresh issues (no prior nudge) → exec_1 batch.
        $this->makeStuckIssue($assignee, null, daysAgo: 3, name: 'Свежая A');
        $this->makeStuckIssue($assignee, null, daysAgo: 3, name: 'Свежая B');

        // Two issues that already got exec_1 → exec_2 batch.
        $b1 = $this->makeStuckIssue($assignee, null, daysAgo: 4, name: 'Зависшая C');
        $b2 = $this->makeStuckIssue($assignee, null, daysAgo: 4, name: 'Зависшая D');
        $this->addNudge($b1, IssueNudge::KIND_EXECUTOR, 1, IssueNudge::TEMPLATE_EXEC_1, daysAgo: 2);
        $this->addNudge($b2, IssueNudge::KIND_EXECUTOR, 1, IssueNudge::TEMPLATE_EXEC_1, daysAgo: 2);

        $texts = [];
        $tg = Mockery::mock(Api::class);
        // Two stages → two messages (merge only within a stage).
        $tg->shouldReceive('sendMessage')->twice()->andReturnUsing(function (array $params) use (&$texts) {
            $texts[] = $params['text'];
            return new Message([]);
        });

        $stats = $this->makeService($tg)->run();

        $this->assertSame(['sent' => 4, 'skipped' => 0, 'failed' => 0], $stats);
        $this->assertCount(2, $texts);

        $exec1 = collect($texts)->first(fn ($t) => str_contains($t, '👋 Привет!'));
        $exec2 = collect($texts)->first(fn ($t) => str_contains($t, 'не двигаются'));
        $this->assertNotNull($exec1, 'exec_1 batch message missing');
        $this->assertNotNull($exec2, 'exec_2 batch message missing');

        $this->assertStringContainsString('«Свежая A»', $exec1);
        $this->assertStringContainsString('«Свежая B»', $exec1);
        $this->assertStringNotContainsString('«Зависшая C»', $exec1);

        $this->assertStringContainsString('«Зависшая C»', $exec2);
        $this->assertStringContainsString('«Зависшая D»', $exec2);
        $this->assertStringNotContainsString('«Свежая A»', $exec2);
    }

    #[Test]
    public function it_batches_escalation_to_one_manager_grouped_by_assignee(): void
    {
        $manager = $this->makeUserWithTg('7201', 'Boss');
        $alice = $this->makeUserWithTg('7202', 'Alice');
        $bob = $this->makeUserWithTg('7203', 'Bob');

        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.uniqid()]);
        $org->users()->attach($manager->id, ['role' => 'manager']);

        $issue1 = $this->makeStuckIssue($alice, $org, daysAgo: 7, name: 'Задача Алисы');
        $this->addNudge($issue1, IssueNudge::KIND_EXECUTOR, 1, IssueNudge::TEMPLATE_EXEC_1, daysAgo: 5);
        $this->addNudge($issue1, IssueNudge::KIND_EXECUTOR, 2, IssueNudge::TEMPLATE_EXEC_2, daysAgo: 3);

        $issue2 = $this->makeStuckIssue($bob, $org, daysAgo: 7, name: 'Задача Боба');
        $this->addNudge($issue2, IssueNudge::KIND_EXECUTOR, 1, IssueNudge::TEMPLATE_EXEC_1, daysAgo: 5);
        $this->addNudge($issue2, IssueNudge::KIND_EXECUTOR, 2, IssueNudge::TEMPLATE_EXEC_2, daysAgo: 3);

        $sentText = null;
        $tg = Mockery::mock(Api::class);
        // Two stuck issues, one shared manager → ONE escalation message.
        $tg->shouldReceive('sendMessage')->once()->andReturnUsing(function (array $params) use (&$sentText) {
            $sentText = $params['text'];
            return new Message([]);
        });

        $stats = $this->makeService($tg)->run();

        $this->assertSame(['sent' => 2, 'skipped' => 0, 'failed' => 0], $stats);
        $this->assertNotNull($sentText);
        $this->assertStringContainsString('🟠 <b>Зависшие задачи требуют внимания</b>', $sentText);
        $this->assertStringContainsString('Alice', $sentText);
        $this->assertStringContainsString('Bob', $sentText);
        $this->assertStringContainsString('«Задача Алисы»', $sentText);
        $this->assertStringContainsString('«Задача Боба»', $sentText);

        // One manager row per issue, all addressed to the same manager.
        $this->assertSame($manager->id, $issue1->nudges()->where('kind', IssueNudge::KIND_MANAGER)->first()->recipient_user_id);
        $this->assertSame($manager->id, $issue2->nudges()->where('kind', IssueNudge::KIND_MANAGER)->first()->recipient_user_id);
    }

    #[Test]
    public function it_escapes_html_in_batched_escalation(): void
    {
        $manager = $this->makeUserWithTg('7301', 'Boss');
        $evil = $this->makeUserWithTg('7302', '<script>alert(1)</script>');
        $normal = $this->makeUserWithTg('7303', 'Normal');

        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.uniqid()]);
        $org->users()->attach($manager->id, ['role' => 'manager']);

        $i1 = $this->makeStuckIssue($evil, $org, daysAgo: 7, name: '<b>InjectedTitle</b>');
        $this->addNudge($i1, IssueNudge::KIND_EXECUTOR, 1, IssueNudge::TEMPLATE_EXEC_1, daysAgo: 5);
        $this->addNudge($i1, IssueNudge::KIND_EXECUTOR, 2, IssueNudge::TEMPLATE_EXEC_2, daysAgo: 3);

        $i2 = $this->makeStuckIssue($normal, $org, daysAgo: 7, name: 'Обычная задача');
        $this->addNudge($i2, IssueNudge::KIND_EXECUTOR, 1, IssueNudge::TEMPLATE_EXEC_1, daysAgo: 5);
        $this->addNudge($i2, IssueNudge::KIND_EXECUTOR, 2, IssueNudge::TEMPLATE_EXEC_2, daysAgo: 3);

        $sentText = null;
        $tg = Mockery::mock(Api::class);
        $tg->shouldReceive('sendMessage')->once()->andReturnUsing(function (array $params) use (&$sentText) {
            $sentText = $params['text'];
            return new Message([]);
        });

        $this->makeService($tg)->run();

        $this->assertNotNull($sentText);
        // Forces the multi-issue batch formatter (count >= 2).
        $this->assertStringNotContainsString('<script>alert(1)</script>', $sentText);
        $this->assertStringNotContainsString('<b>InjectedTitle</b>', $sentText);
        $this->assertStringContainsString('&lt;script&gt;', $sentText);
        $this->assertStringContainsString('&lt;b&gt;InjectedTitle&lt;/b&gt;', $sentText);
    }

    #[Test]
    public function batch_send_failure_burns_the_attempt_for_every_issue_in_the_bucket(): void
    {
        // Documented, deliberate trade-off: a batched message is one send for N
        // issues, so a single failure marks ALL N as failed — and because FAILED
        // rows still enter the series, the attempt is "burned" (not retried next
        // run). This test pins that semantic so it is not "fixed" by accident.
        $assignee = $this->makeUserWithTg('7401', 'Alice');

        $issues = [];
        foreach (['Задача 1', 'Задача 2', 'Задача 3'] as $name) {
            $issues[] = $this->makeStuckIssue($assignee, null, daysAgo: 3, name: $name);
        }

        $tg = Mockery::mock(Api::class);
        // Three day-2 issues → ONE batched send, and it fails. No second send on
        // the re-run (the burned attempt is never retried) — enforced by once().
        $tg->shouldReceive('sendMessage')->once()->andThrow(new \RuntimeException('boom 429'));

        $service = $this->makeService($tg);
        $stats = $service->run();

        $this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 3], $stats);
        foreach ($issues as $issue) {
            $n = $issue->nudges()->where('template_key', IssueNudge::TEMPLATE_EXEC_1)->first();
            $this->assertNotNull($n);
            $this->assertSame(IssueNudge::STATUS_FAILED, $n->status);
        }

        // Re-run: exec_1 is burned for all three (day 3 < 4 so exec_2 not yet due) →
        // no further send, no new rows.
        $service->run();
        foreach ($issues as $issue) {
            $this->assertSame(1, $issue->nudges()->where('template_key', IssueNudge::TEMPLATE_EXEC_1)->count());
        }
    }
}
