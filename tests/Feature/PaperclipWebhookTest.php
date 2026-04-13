<?php

namespace Tests\Feature;

use App\Enums\AgentTaskRunStatus;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaperclipWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUE_ID = 'paperclip-issue-webhook-test';

    // -------------------------------------------------------------------------
    // agent.run.started
    // -------------------------------------------------------------------------

    #[Test]
    public function started_event_transitions_queued_run_to_processing(): void
    {
        [$task, $run] = $this->createPaperclipRun(status: 'queued');

        $this->postWebhook('agent.run.started', ['issueId' => self::ISSUE_ID])
            ->assertOk();

        $this->assertSame(AgentTaskRunStatus::PROCESSING->value, $run->fresh()->status->value);
    }

    #[Test]
    public function started_event_is_idempotent_when_run_already_processing(): void
    {
        [$task, $run] = $this->createPaperclipRun(status: 'processing');

        $this->postWebhook('agent.run.started', ['issueId' => self::ISSUE_ID])
            ->assertOk();

        $this->assertSame(AgentTaskRunStatus::PROCESSING->value, $run->fresh()->status->value);
    }

    // -------------------------------------------------------------------------
    // agent.run.finished
    // -------------------------------------------------------------------------

    #[Test]
    public function finished_event_completes_run_with_output_from_planDocument(): void
    {
        [$task, $run] = $this->createPaperclipRun(status: 'processing');

        $this->postWebhook('agent.run.finished', [
            'issueId'      => self::ISSUE_ID,
            'planDocument' => 'Here is the completed plan.',
        ])->assertOk();

        $run  = $run->fresh();
        $task = $task->fresh();

        $this->assertSame(AgentTaskRunStatus::COMPLETED->value, $run->status->value);
        $this->assertSame('Here is the completed plan.', $run->output);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->error_message);

        $this->assertNotNull($task->last_completed_at);
        $this->assertNull($task->locked_at);
        $this->assertFalse($task->enabled); // one_off task
    }

    #[Test]
    public function finished_event_completes_run_with_output_from_output_field(): void
    {
        [$task, $run] = $this->createPaperclipRun(status: 'processing');

        $this->postWebhook('agent.run.finished', [
            'issueId' => self::ISSUE_ID,
            'output'  => 'Task result via output field.',
        ])->assertOk();

        $this->assertSame('Task result via output field.', $run->fresh()->output);
    }

    #[Test]
    public function finished_event_is_idempotent_when_run_already_completed(): void
    {
        [$task, $run] = $this->createPaperclipRun(status: 'completed');

        $this->postWebhook('agent.run.finished', [
            'issueId'      => self::ISSUE_ID,
            'planDocument' => 'Should be ignored.',
        ])->assertOk();

        // Output must not be overwritten
        $this->assertNotSame('Should be ignored.', $run->fresh()->output);
    }

    // -------------------------------------------------------------------------
    // agent.run.failed
    // -------------------------------------------------------------------------

    #[Test]
    public function failed_event_marks_run_as_failed_with_error_message(): void
    {
        [$task, $run] = $this->createPaperclipRun(status: 'processing');

        $this->postWebhook('agent.run.failed', [
            'issueId' => self::ISSUE_ID,
            'error'   => 'Something went wrong in the agent.',
        ])->assertOk();

        $run  = $run->fresh();
        $task = $task->fresh();

        $this->assertSame(AgentTaskRunStatus::FAILED->value, $run->status->value);
        $this->assertSame('Something went wrong in the agent.', $run->error_message);
        $this->assertNotNull($run->finished_at);

        $this->assertNotNull($task->last_failed_at);
        $this->assertNull($task->locked_at);
    }

    #[Test]
    public function failed_event_uses_default_message_when_no_error_provided(): void
    {
        [$task, $run] = $this->createPaperclipRun(status: 'processing');

        $this->postWebhook('agent.run.failed', ['issueId' => self::ISSUE_ID])
            ->assertOk();

        $this->assertNotEmpty($run->fresh()->error_message);
    }

    #[Test]
    public function failed_event_is_idempotent_when_run_already_failed(): void
    {
        [$task, $run] = $this->createPaperclipRun(status: 'failed');

        $this->postWebhook('agent.run.failed', [
            'issueId' => self::ISSUE_ID,
            'error'   => 'Should be ignored.',
        ])->assertOk();

        $this->assertNotSame('Should be ignored.', $run->fresh()->error_message);
    }

    // -------------------------------------------------------------------------
    // agent.run.cancelled
    // -------------------------------------------------------------------------

    #[Test]
    public function cancelled_event_marks_run_as_failed_with_cancellation_message(): void
    {
        [$task, $run] = $this->createPaperclipRun(status: 'processing');

        $this->postWebhook('agent.run.cancelled', [
            'issueId' => self::ISSUE_ID,
            'reason'  => 'User requested cancellation.',
        ])->assertOk();

        $run  = $run->fresh();
        $task = $task->fresh();

        $this->assertSame(AgentTaskRunStatus::FAILED->value, $run->status->value);
        $this->assertSame('User requested cancellation.', $run->error_message);
        $this->assertNotNull($task->last_failed_at);
        $this->assertNull($task->locked_at);
    }

    #[Test]
    public function cancelled_event_uses_default_message_when_no_reason_provided(): void
    {
        [$task, $run] = $this->createPaperclipRun(status: 'processing');

        $this->postWebhook('agent.run.cancelled', ['issueId' => self::ISSUE_ID])
            ->assertOk();

        $this->assertStringContainsStringIgnoringCase('cancel', $run->fresh()->error_message);
    }

    // -------------------------------------------------------------------------
    // Unknown events
    // -------------------------------------------------------------------------

    #[Test]
    public function unknown_event_returns_error(): void
    {
        $this->postWebhook('agent.run.unknown', ['issueId' => self::ISSUE_ID])
            ->assertStatus(500);
    }

    // -------------------------------------------------------------------------
    // Run not found (graceful handling)
    // -------------------------------------------------------------------------

    #[Test]
    public function finished_event_for_unknown_issue_returns_ok(): void
    {
        $this->postWebhook('agent.run.finished', ['issueId' => 'non-existent-issue'])
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // Bug regression: missing issueId
    // -------------------------------------------------------------------------

    #[Test]
    public function finished_event_without_issue_id_returns_error(): void
    {
        $this->postWebhook('agent.run.finished', ['planDocument' => 'no id here'])
            ->assertStatus(500);
    }

    // -------------------------------------------------------------------------
    // Bug regression: orphaned run (task deleted)
    // -------------------------------------------------------------------------

    #[Test]
    public function finished_event_returns_ok_when_task_deleted(): void
    {
        [$task, $run] = $this->createPaperclipRun(status: 'processing');
        $task->delete();

        $this->postWebhook('agent.run.finished', [
            'issueId'      => self::ISSUE_ID,
            'planDocument' => 'result',
        ])->assertOk();
    }

    #[Test]
    public function failed_event_returns_ok_when_task_deleted(): void
    {
        [$task, $run] = $this->createPaperclipRun(status: 'processing');
        $task->delete();

        $this->postWebhook('agent.run.failed', [
            'issueId' => self::ISSUE_ID,
            'error'   => 'something failed',
        ])->assertOk();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function postWebhook(string $event, array $data): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/paperclip/webhook', [
            'event' => $event,
            'data'  => $data,
        ]);
    }

    private function createPaperclipRun(string $status = 'processing'): array
    {
        $user = User::factory()->create();

        $task = AgentTask::create([
            'user_id'         => $user->id,
            'name'            => 'Webhook test task',
            'prompt'          => 'Do something',
            'schedule_type'   => 'one_off',
            'execution_mode'  => 'paperclip',
            'agent_task_type' => 'background',
            'output_mode'     => 'plain',
            'next_run_at'     => now()->subMinute(),
            'enabled'         => true,
            'locked_at'       => now(),
            'max_attempts'    => 1,
        ]);

        $run = AgentTaskRun::create([
            'agent_task_id'      => $task->id,
            'status'             => $status,
            'scheduled_for'      => now()->subMinute(),
            'paperclip_issue_id' => self::ISSUE_ID,
        ]);

        return [$task, $run];
    }
}
