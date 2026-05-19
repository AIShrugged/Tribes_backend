<?php

namespace Tests\Feature;

use App\Enums\AgentTaskRunStatus;
use App\Events\AgentTaskRunFinalized;
use App\Listeners\SendPaperclipTaskNotification;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SendPaperclipTaskNotificationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function resolves_assignee_personal_telegram_when_assignee_has_one(): void
    {
        $assignee = $this->userWithTelegram('100100');
        $initiator = $this->userWithTelegram('999999');
        $issue = $this->issueFor($initiator, $assignee);
        $task = $this->paperclipTaskFor($initiator, $issue);

        $targets = (new SendPaperclipTaskNotification())->resolveTargets($task, $issue);

        $this->assertCount(1, $targets);
        $this->assertSame('100100', $targets[0]['chat_id']);
        $this->assertSame('personal', $targets[0]['kind']);
    }

    #[Test]
    public function falls_back_to_task_user_when_assignee_id_is_null(): void
    {
        $initiator = $this->userWithTelegram('200200');
        $issue = $this->issueFor($initiator, null);
        $task = $this->paperclipTaskFor($initiator, $issue);

        $targets = (new SendPaperclipTaskNotification())->resolveTargets($task, $issue);

        $this->assertCount(1, $targets);
        $this->assertSame('200200', $targets[0]['chat_id']);
        $this->assertSame('personal', $targets[0]['kind']);
    }

    #[Test]
    public function silently_skips_when_assignee_has_no_telegram_user(): void
    {
        $assignee = User::factory()->create(); // no TelegramUser
        $initiator = $this->userWithTelegram('300300');
        $issue = $this->issueFor($initiator, $assignee);
        $task = $this->paperclipTaskFor($initiator, $issue);

        $targets = (new SendPaperclipTaskNotification())->resolveTargets($task, $issue);

        // Per decision: assignee has no telegramUser → skip silently (do NOT fall back to initiator)
        $this->assertSame([], $targets);
    }

    #[Test]
    public function returns_empty_when_no_linked_issue(): void
    {
        $initiator = $this->userWithTelegram('400400');
        $task = $this->paperclipTaskFor($initiator, null);

        $targets = (new SendPaperclipTaskNotification())->resolveTargets($task, null);

        // No Issue (validator/follow-up tasks) → no personal notification
        $this->assertSame([], $targets);
    }

    #[Test]
    public function includes_team_override_in_addition_to_personal(): void
    {
        $assignee = $this->userWithTelegram('500500');
        $initiator = $this->userWithTelegram('999999');
        $issue = $this->issueFor($initiator, $assignee);
        $task = $this->paperclipTaskFor($initiator, $issue);
        $task->update([
            'notification_telegram_chat_id' => 70000,
            'notification_telegram_thread_id' => 42,
        ]);

        $targets = (new SendPaperclipTaskNotification())->resolveTargets($task->fresh(), $issue);

        $this->assertCount(2, $targets);
        $this->assertSame('personal', $targets[0]['kind']);
        $this->assertSame('500500', $targets[0]['chat_id']);
        $this->assertSame('team_override', $targets[1]['kind']);
        $this->assertSame('70000', $targets[1]['chat_id']);
        $this->assertSame(42, $targets[1]['thread_id']);
    }

    #[Test]
    public function returns_only_team_override_when_personal_unavailable(): void
    {
        $initiator = User::factory()->create(); // no TelegramUser
        $task = $this->paperclipTaskFor($initiator, null);
        $task->update(['notification_telegram_chat_id' => 80000]);

        $targets = (new SendPaperclipTaskNotification())->resolveTargets($task->fresh(), null);

        $this->assertCount(1, $targets);
        $this->assertSame('team_override', $targets[0]['kind']);
        $this->assertSame('80000', $targets[0]['chat_id']);
    }

    #[Test]
    public function handle_is_noop_for_non_paperclip_tasks(): void
    {
        $user = $this->userWithTelegram('111000');
        $issue = $this->issueFor($user, $user);
        $task = $this->paperclipTaskFor($user, $issue);
        $task->update(['execution_mode' => 'inline']);
        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => AgentTaskRunStatus::COMPLETED->value,
            'output' => 'done',
        ]);

        // Should not throw, should not attempt any send (no overload mock here)
        (new SendPaperclipTaskNotification())->handle(
            new AgentTaskRunFinalized($run->fresh(), AgentTaskRunStatus::COMPLETED)
        );

        $this->assertTrue(true);
    }

    #[Test]
    public function build_message_includes_status_pr_artifacts_and_result(): void
    {
        config(['app.frontend_url' => 'https://wanda.example.com']);

        $assignee = $this->userWithTelegram('100');
        $initiator = $this->userWithTelegram('200');
        $issue = $this->issueFor($initiator, $assignee);
        $issue->update([
            'pr_url' => 'https://github.com/acme/repo/pull/42',
            'pr_repository' => 'acme/repo',
            'pr_number' => 42,
        ]);
        $task = $this->paperclipTaskFor($initiator, $issue);
        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => AgentTaskRunStatus::COMPLETED->value,
            'output' => 'Reviewed plan and opened PR.',
            'paperclip_issue_id' => 'pc-abc-123',
            'metadata' => [
                // Real Paperclip shape from getIssueAttachments / callback payloads
                'paperclip_attachments' => [
                    ['id' => 'att-1', 'filename' => 'report.md', 'mimeType' => 'text/markdown', 'size' => 100],
                    ['id' => 'att-2', 'filename' => 'diagram.png', 'mimeType' => 'image/png', 'size' => 5000],
                ],
            ],
        ]);

        $text = (new SendPaperclipTaskNotification())->buildMessage(
            $task->fresh(),
            $run->fresh(),
            AgentTaskRunStatus::COMPLETED,
            $issue->fresh(),
        );

        $this->assertStringContainsString('выполнена', $text);
        $this->assertStringContainsString('<b>Task:</b> ' . $task->name, $text);
        $this->assertStringContainsString('pc-abc-123', $text);
        $this->assertStringContainsString('href="https://github.com/acme/repo/pull/42"', $text);
        $this->assertStringContainsString('acme/repo#42', $text);
        $this->assertStringContainsString('Reviewed plan and opened PR.', $text);
        $this->assertStringContainsString('report.md', $text);
        $this->assertStringContainsString('diagram.png', $text);
        $this->assertStringContainsString('https://wanda.example.com/dashboard/issues/' . $issue->id, $text);
    }

    #[Test]
    public function build_message_escapes_html_in_task_name_and_body(): void
    {
        $user = $this->userWithTelegram('100');
        $issue = $this->issueFor($user, $user);
        $task = $this->paperclipTaskFor($user, $issue);
        $task->update(['name' => 'fix <script>alert(1)</script> & "things"']);
        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => AgentTaskRunStatus::FAILED->value,
            'error_message' => 'oh no <b>raw</b> & broken',
        ]);

        $text = (new SendPaperclipTaskNotification())->buildMessage(
            $task->fresh(),
            $run->fresh(),
            AgentTaskRunStatus::FAILED,
            $issue->fresh(),
        );

        // Raw HTML must not appear; entities must
        $this->assertStringNotContainsString('<script>', $text);
        $this->assertStringNotContainsString('<b>raw</b>', $text);
        $this->assertStringContainsString('&lt;script&gt;', $text);
        $this->assertStringContainsString('&lt;b&gt;raw&lt;/b&gt;', $text);
        // Our own <b> wrappers stay
        $this->assertStringContainsString('<b>Task:</b>', $text);
    }

    #[Test]
    public function build_message_for_paused_status_includes_reason_and_unblock_hint(): void
    {
        $user = $this->userWithTelegram('100');
        $issue = $this->issueFor($user, $user);
        $task = $this->paperclipTaskFor($user, $issue);
        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => AgentTaskRunStatus::PAUSED->value,
            'error_message' => 'Нужен доступ к проду',
        ]);

        $text = (new SendPaperclipTaskNotification())->buildMessage(
            $task->fresh(),
            $run->fresh(),
            AgentTaskRunStatus::PAUSED,
            $issue->fresh(),
        );

        $this->assertStringContainsString('заблокирована', $text);
        $this->assertStringContainsString('Нужен доступ к проду', $text);
        $this->assertStringContainsString('Устраните блокировку', $text);
    }

    #[Test]
    public function collect_artifacts_handles_url_only_and_string_entries(): void
    {
        $user = $this->userWithTelegram('100');
        $issue = $this->issueFor($user, $user);
        $task = $this->paperclipTaskFor($user, $issue);
        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => AgentTaskRunStatus::COMPLETED->value,
            'output' => '',
            'metadata' => [
                'paperclip_artifacts' => [
                    'https://files.example.com/a.log',
                    ['url' => 'https://files.example.com/b.json'],
                    ['filename' => 'inline.txt'],
                ],
            ],
        ]);

        $text = (new SendPaperclipTaskNotification())->buildMessage(
            $task->fresh(),
            $run->fresh(),
            AgentTaskRunStatus::COMPLETED,
            $issue->fresh(),
        );

        // String entries → basename + href
        $this->assertStringContainsString('href="https://files.example.com/a.log"', $text);
        $this->assertStringContainsString('>a.log</a>', $text);
        // Array with url-only → basename + href
        $this->assertStringContainsString('href="https://files.example.com/b.json"', $text);
        $this->assertStringContainsString('>b.json</a>', $text);
        // Array with filename only → plain text bullet
        $this->assertStringContainsString('• inline.txt', $text);
    }

    #[Test]
    public function build_message_for_failed_status_includes_error(): void
    {
        $user = $this->userWithTelegram('100');
        $issue = $this->issueFor($user, $user);
        $task = $this->paperclipTaskFor($user, $issue);
        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => AgentTaskRunStatus::FAILED->value,
            'error_message' => 'paperclip timeout',
        ]);

        $text = (new SendPaperclipTaskNotification())->buildMessage(
            $task->fresh(),
            $run->fresh(),
            AgentTaskRunStatus::FAILED,
            $issue->fresh(),
        );

        $this->assertStringContainsString('ошибка', $text);
        $this->assertStringContainsString('paperclip timeout', $text);
    }

    #[Test]
    public function resolve_issue_finds_via_metadata_issue_id(): void
    {
        $user = User::factory()->create();
        $issue = $this->issueFor($user, $user);
        $task = $this->paperclipTaskFor($user, $issue);

        $resolved = (new SendPaperclipTaskNotification())->resolveIssue($task->fresh());

        $this->assertNotNull($resolved);
        $this->assertSame($issue->id, $resolved->id);
    }

    #[Test]
    public function resolve_issue_returns_null_when_metadata_empty(): void
    {
        $user = User::factory()->create();
        $task = $this->paperclipTaskFor($user, null);

        $resolved = (new SendPaperclipTaskNotification())->resolveIssue($task);

        $this->assertNull($resolved);
    }

    // ---------------------------------------------------------------------

    private function userWithTelegram(string $tgId): User
    {
        $user = User::factory()->create();
        TelegramUser::create([
            'user_id' => $user->id,
            'telegram_user_id' => $tgId,
        ]);

        return $user;
    }

    private function issueFor(User $owner, ?User $assignee): Issue
    {
        $org = Organization::firstOrCreate(
            ['slug' => 'paperclip-notif-test-org'],
            ['name' => 'Paperclip Notif Test Org'],
        );

        return Issue::create([
            'user_id' => $owner->id,
            'assignee_id' => $assignee?->id,
            'organization_id' => $org->id,
            'team_id' => null,
            'name' => 'Test issue',
            'description' => '',
            'type' => 'development',
            'status' => 'in_progress',
        ]);
    }

    private function paperclipTaskFor(User $owner, ?Issue $issue): AgentTask
    {
        $metadata = $issue ? ['issue_id' => $issue->id] : [];

        return AgentTask::create([
            'user_id' => $owner->id,
            'organization_id' => $issue?->organization_id,
            'name' => 'Paperclip task',
            'prompt' => 'Do something',
            'schedule_type' => 'one_off',
            'execution_mode' => 'paperclip',
            'agent_task_type' => 'background',
            'output_mode' => 'plain',
            'enabled' => true,
            'max_attempts' => 1,
            'metadata' => $metadata,
        ]);
    }
}
