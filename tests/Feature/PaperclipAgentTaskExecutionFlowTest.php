<?php

namespace Tests\Feature;

use App\Enums\AgentTaskRunStatus;
use App\Jobs\CheckPaperclipIssueStatusJob;
use App\Jobs\RunAgentTaskJob;
use App\Models\AgentActivityLog;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaperclipAgentTaskExecutionFlowTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUE_ID = 'paperclip-issue-abc123';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'paperclip.api_url'           => 'http://paperclip-test.local',
            'paperclip.api_key'           => 'test-api-key',
            'paperclip.company_id'        => 'company-test',
            'paperclip.agent_id'          => 'agent-test',
            'paperclip.polling.intervals' => [0],  // no delay in tests
            'paperclip.polling.max_seconds' => 60,
        ]);
    }

    // -------------------------------------------------------------------------
    // RunAgentTaskJob: creates issue and dispatches CheckJob
    // -------------------------------------------------------------------------

    #[Test]
    public function job_creates_issue_dispatches_check_job_and_leaves_run_in_processing(): void
    {
        Queue::fake();

        Http::fake([
            'paperclip-test.local/api/companies/company-test/issues' => Http::response([
                'id'     => self::ISSUE_ID,
                'status' => 'todo',
            ], 201),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun();

        $job = new RunAgentTaskJob($task->id, $run->id, 1);
        $this->app->call([$job, 'handle']);

        $run  = $run->fresh();
        $task = $task->fresh();

        $this->assertSame(AgentTaskRunStatus::PROCESSING->value, $run->status->value);
        $this->assertSame(self::ISSUE_ID, $run->paperclip_issue_id);
        $this->assertNull($run->finished_at);
        $this->assertNotNull($task->locked_at);

        Queue::assertPushed(CheckPaperclipIssueStatusJob::class, function ($job) use ($run) {
            return $job->agentTaskRunId === $run->id
                && $job->pollStep === 0
                && $job->elapsedSeconds === 0;
        });
    }

    #[Test]
    public function job_fails_run_when_paperclip_api_returns_error(): void
    {
        Http::fake([
            'paperclip-test.local/api/companies/company-test/issues' => Http::response([
                'error' => 'Unauthorized',
            ], 401),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun();

        $job = new RunAgentTaskJob($task->id, $run->id, 1);
        try {
            $this->app->call([$job, 'handle']);
        } catch (\RuntimeException) {}

        $this->assertSame(AgentTaskRunStatus::FAILED->value, $run->fresh()->status->value);
        $this->assertStringContainsString('401', $run->fresh()->error_message);
    }

    #[Test]
    public function issue_is_created_with_correct_payload(): void
    {
        Queue::fake();

        Http::fake([
            'paperclip-test.local/api/companies/company-test/issues' => Http::response([
                'id' => self::ISSUE_ID, 'status' => 'todo',
            ], 201),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun(name: 'My task', prompt: 'Do something important');

        $this->app->call([new RunAgentTaskJob($task->id, $run->id, 1), 'handle']);

        Http::assertSent(function (Request $request) use ($task) {
            if (! str_contains($request->url(), '/api/companies/company-test/issues')) {
                return false;
            }
            $body = $request->data();

            return $body['title'] === $task->name
                && str_contains($body['description'], $task->prompt)
                && $body['assigneeAgentId'] === 'agent-test'
                && $body['status'] === 'todo'
                && ! isset($body['callbackUrl']);
        });
    }

    // -------------------------------------------------------------------------
    // CheckPaperclipIssueStatusJob: completes run
    // -------------------------------------------------------------------------

    #[Test]
    public function check_job_completes_run_from_plan_document(): void
    {
        Http::fake([
            'paperclip-test.local/api/issues/'.self::ISSUE_ID => Http::response([
                'id'           => self::ISSUE_ID,
                'status'       => 'done',
                'planDocument' => 'The task is done. Here is the result.',
            ], 200),
            'paperclip-test.local/api/companies/company-test/activity*' => Http::response([], 200),
            'paperclip-test.local/api/issues/'.self::ISSUE_ID.'/attachments' => Http::response([], 200),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun();
        $run->update(['paperclip_issue_id' => self::ISSUE_ID, 'status' => 'processing']);

        $this->app->call([new CheckPaperclipIssueStatusJob($run->id, 0, 0), 'handle']);

        $run  = $run->fresh();
        $task = $task->fresh();

        $this->assertSame(AgentTaskRunStatus::COMPLETED->value, $run->status->value);
        $this->assertSame('The task is done. Here is the result.', $run->output);
        $this->assertNotNull($run->finished_at);
        $this->assertFalse($task->enabled);
        $this->assertNull($task->locked_at);
        $this->assertNotNull($task->last_completed_at);
    }

    #[Test]
    public function check_job_completes_run_from_last_comment_when_no_plan_document(): void
    {
        Http::fake([
            'paperclip-test.local/api/issues/'.self::ISSUE_ID => Http::response([
                'id' => self::ISSUE_ID, 'status' => 'done', 'planDocument' => null,
            ], 200),
            'paperclip-test.local/api/companies/company-test/activity*' => Http::response([], 200),
            'paperclip-test.local/api/issues/'.self::ISSUE_ID.'/attachments' => Http::response([], 200),
            'paperclip-test.local/api/issues/'.self::ISSUE_ID.'/comments' => Http::response([
                ['body' => 'First comment'],
                ['body' => 'Final result comment'],
            ], 200),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun();
        $run->update(['paperclip_issue_id' => self::ISSUE_ID, 'status' => 'processing']);

        $this->app->call([new CheckPaperclipIssueStatusJob($run->id, 0, 0), 'handle']);

        $this->assertSame(AgentTaskRunStatus::COMPLETED->value, $run->fresh()->status->value);
        $this->assertSame('Final result comment', $run->fresh()->output);
    }

    // -------------------------------------------------------------------------
    // CheckPaperclipIssueStatusJob: re-queues when in_progress
    // -------------------------------------------------------------------------

    #[Test]
    public function check_job_requeues_itself_when_issue_in_progress(): void
    {
        Queue::fake();

        Http::fake([
            'paperclip-test.local/api/issues/'.self::ISSUE_ID => Http::response([
                'id' => self::ISSUE_ID, 'status' => 'in_progress',
            ], 200),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun();
        $run->update(['paperclip_issue_id' => self::ISSUE_ID, 'status' => 'processing']);

        $this->app->call([new CheckPaperclipIssueStatusJob($run->id, 0, 0), 'handle']);

        $this->assertSame(AgentTaskRunStatus::PROCESSING->value, $run->fresh()->status->value);

        Queue::assertPushed(CheckPaperclipIssueStatusJob::class, function ($job) use ($run) {
            return $job->agentTaskRunId === $run->id
                && $job->pollStep === 1
                && $job->elapsedSeconds === 0; // interval=0 in tests
        });
    }

    // -------------------------------------------------------------------------
    // CheckPaperclipIssueStatusJob: fails on cancelled
    // -------------------------------------------------------------------------

    #[Test]
    public function check_job_fails_run_when_issue_cancelled(): void
    {
        Http::fake([
            'paperclip-test.local/api/issues/'.self::ISSUE_ID => Http::response([
                'id' => self::ISSUE_ID, 'status' => 'cancelled',
            ], 200),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun();
        $run->update(['paperclip_issue_id' => self::ISSUE_ID, 'status' => 'processing']);

        $this->app->call([new CheckPaperclipIssueStatusJob($run->id, 0, 0), 'handle']);

        $run  = $run->fresh();
        $task = $task->fresh();

        $this->assertSame(AgentTaskRunStatus::FAILED->value, $run->status->value);
        $this->assertStringContainsString('cancelled', $run->error_message);
        $this->assertNotNull($task->last_failed_at);
        $this->assertNull($task->locked_at);
    }

    // -------------------------------------------------------------------------
    // CheckPaperclipIssueStatusJob: fails on timeout
    // -------------------------------------------------------------------------

    #[Test]
    public function check_job_fails_run_on_timeout(): void
    {
        Http::fake([
            'paperclip-test.local/api/issues/'.self::ISSUE_ID => Http::response([
                'id' => self::ISSUE_ID, 'status' => 'in_progress',
            ], 200),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun();
        $run->update(['paperclip_issue_id' => self::ISSUE_ID, 'status' => 'processing']);

        // elapsed already at max
        $this->app->call([new CheckPaperclipIssueStatusJob($run->id, 0, 60), 'handle']);

        $run = $run->fresh();

        $this->assertSame(AgentTaskRunStatus::FAILED->value, $run->status->value);
        $this->assertStringContainsString('timeout', $run->error_message);
    }

    // -------------------------------------------------------------------------
    // CheckPaperclipIssueStatusJob: idempotent
    // -------------------------------------------------------------------------

    #[Test]
    public function check_job_skips_already_completed_run(): void
    {
        Http::fake(); // no HTTP calls expected

        [$task, $run] = $this->createPaperclipTaskAndRun();
        $run->update(['paperclip_issue_id' => self::ISSUE_ID, 'status' => 'completed']);

        $this->app->call([new CheckPaperclipIssueStatusJob($run->id, 0, 0), 'handle']);

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Activity sync
    // -------------------------------------------------------------------------

    #[Test]
    public function check_job_saves_paperclip_activity_when_issue_done(): void
    {
        Http::fake([
            'paperclip-test.local/api/issues/'.self::ISSUE_ID => Http::response([
                'id'           => self::ISSUE_ID,
                'status'       => 'done',
                'planDocument' => 'Done.',
            ], 200),
            'paperclip-test.local/api/issues/'.self::ISSUE_ID.'/attachments' => Http::response([], 200),
            'paperclip-test.local/api/companies/company-test/activity*' => Http::response([
                [
                    'action'     => 'created',
                    'entityType' => 'issue',
                    'entityId'   => self::ISSUE_ID,
                    'actor'      => ['id' => 'agent-test', 'name' => 'Paperclip Agent'],
                    'details'    => ['status' => 'todo'],
                    'createdAt'  => '2026-04-15T10:00:00Z',
                ],
                [
                    'action'     => 'updated',
                    'entityType' => 'issue',
                    'entityId'   => self::ISSUE_ID,
                    'actor'      => ['id' => 'agent-test', 'name' => 'Paperclip Agent'],
                    'details'    => ['status' => 'done'],
                    'createdAt'  => '2026-04-15T10:05:00Z',
                ],
            ], 200),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun();
        $run->update(['paperclip_issue_id' => self::ISSUE_ID, 'status' => 'processing']);

        $this->app->call([new CheckPaperclipIssueStatusJob($run->id, 0, 0), 'handle']);

        $this->assertSame(AgentTaskRunStatus::COMPLETED->value, $run->fresh()->status->value);

        $logs = AgentActivityLog::where('agent_task_run_id', $run->id)->get();
        $this->assertCount(2, $logs);

        $first = $logs->first();
        $this->assertSame('paperclip_created', $first->tool_name);
        $this->assertSame($run->id, $first->agent_task_run_id);
        $this->assertArrayHasKey('action', $first->tool_result);
        $this->assertSame('2026-04-15 10:00:00', $first->created_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function check_job_saves_activity_when_issue_cancelled(): void
    {
        Http::fake([
            'paperclip-test.local/api/issues/'.self::ISSUE_ID => Http::response([
                'id' => self::ISSUE_ID, 'status' => 'cancelled',
            ], 200),
            'paperclip-test.local/api/companies/company-test/activity*' => Http::response([
                [
                    'action'     => 'cancelled',
                    'entityType' => 'issue',
                    'entityId'   => self::ISSUE_ID,
                    'actor'      => ['id' => 'agent-test', 'name' => 'Paperclip Agent'],
                    'details'    => [],
                    'createdAt'  => '2026-04-15T10:03:00Z',
                ],
            ], 200),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun();
        $run->update(['paperclip_issue_id' => self::ISSUE_ID, 'status' => 'processing']);

        $this->app->call([new CheckPaperclipIssueStatusJob($run->id, 0, 0), 'handle']);

        $this->assertSame(AgentTaskRunStatus::FAILED->value, $run->fresh()->status->value);

        $logs = AgentActivityLog::where('agent_task_run_id', $run->id)->get();
        $this->assertCount(1, $logs);
        $this->assertSame('paperclip_cancelled', $logs->first()->tool_name);
    }

    #[Test]
    public function check_job_still_completes_run_when_activity_sync_fails(): void
    {
        Http::fake([
            'paperclip-test.local/api/issues/'.self::ISSUE_ID => Http::response([
                'id' => self::ISSUE_ID, 'status' => 'done', 'planDocument' => 'Done.',
            ], 200),
            'paperclip-test.local/api/companies/company-test/activity*' => Http::response([
                'error' => 'Internal Server Error',
            ], 500),
            'paperclip-test.local/api/issues/'.self::ISSUE_ID.'/attachments' => Http::response([], 200),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun();
        $run->update(['paperclip_issue_id' => self::ISSUE_ID, 'status' => 'processing']);

        $this->app->call([new CheckPaperclipIssueStatusJob($run->id, 0, 0), 'handle']);

        // Run completes despite activity sync failure
        $this->assertSame(AgentTaskRunStatus::COMPLETED->value, $run->fresh()->status->value);
        $this->assertSame(0, AgentActivityLog::where('agent_task_run_id', $run->id)->count());
    }

    // -------------------------------------------------------------------------
    // Attachments
    // -------------------------------------------------------------------------

    #[Test]
    public function check_job_saves_attachments_to_run_metadata_when_done(): void
    {
        Http::fake([
            'paperclip-test.local/api/issues/'.self::ISSUE_ID => Http::response([
                'id'           => self::ISSUE_ID,
                'status'       => 'done',
                'planDocument' => 'Result here.',
            ], 200),
            'paperclip-test.local/api/companies/company-test/activity*' => Http::response([], 200),
            'paperclip-test.local/api/issues/'.self::ISSUE_ID.'/attachments' => Http::response([
                ['id' => 'att-1', 'filename' => 'report.pdf', 'mimeType' => 'application/pdf', 'size' => 12345],
                ['id' => 'att-2', 'filename' => 'data.csv',   'mimeType' => 'text/csv',         'size' => 678],
            ], 200),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun();
        $run->update(['paperclip_issue_id' => self::ISSUE_ID, 'status' => 'processing']);

        $this->app->call([new CheckPaperclipIssueStatusJob($run->id, 0, 0), 'handle']);

        $run = $run->fresh();
        $this->assertSame(AgentTaskRunStatus::COMPLETED->value, $run->status->value);

        $attachments = data_get($run->metadata, 'paperclip_attachments');
        $this->assertCount(2, $attachments);
        $this->assertSame('att-1', $attachments[0]['id']);
        $this->assertSame('report.pdf', $attachments[0]['filename']);
        $this->assertSame('att-2', $attachments[1]['id']);
    }

    #[Test]
    public function check_job_still_completes_when_attachments_api_fails(): void
    {
        Http::fake([
            'paperclip-test.local/api/issues/'.self::ISSUE_ID => Http::response([
                'id' => self::ISSUE_ID, 'status' => 'done', 'planDocument' => 'Done.',
            ], 200),
            'paperclip-test.local/api/companies/company-test/activity*' => Http::response([], 200),
            'paperclip-test.local/api/issues/'.self::ISSUE_ID.'/attachments' => Http::response([
                'error' => 'Internal Server Error',
            ], 500),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun();
        $run->update(['paperclip_issue_id' => self::ISSUE_ID, 'status' => 'processing']);

        $this->app->call([new CheckPaperclipIssueStatusJob($run->id, 0, 0), 'handle']);

        $run = $run->fresh();
        $this->assertSame(AgentTaskRunStatus::COMPLETED->value, $run->status->value);
        $this->assertEmpty(data_get($run->metadata, 'paperclip_attachments'));
    }

    #[Test]
    public function check_job_does_not_save_attachments_when_list_is_empty(): void
    {
        Http::fake([
            'paperclip-test.local/api/issues/'.self::ISSUE_ID => Http::response([
                'id' => self::ISSUE_ID, 'status' => 'done', 'planDocument' => 'Done.',
            ], 200),
            'paperclip-test.local/api/companies/company-test/activity*' => Http::response([], 200),
            'paperclip-test.local/api/issues/'.self::ISSUE_ID.'/attachments' => Http::response([], 200),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun();
        $run->update(['paperclip_issue_id' => self::ISSUE_ID, 'status' => 'processing']);

        $this->app->call([new CheckPaperclipIssueStatusJob($run->id, 0, 0), 'handle']);

        $run = $run->fresh();
        $this->assertSame(AgentTaskRunStatus::COMPLETED->value, $run->status->value);
        $this->assertNull(data_get($run->metadata, 'paperclip_attachments'));
    }

    // -------------------------------------------------------------------------
    // Blocked status
    // -------------------------------------------------------------------------

    #[Test]
    public function check_job_pauses_run_when_issue_blocked(): void
    {
        Http::fake([
            'paperclip-test.local/api/issues/'.self::ISSUE_ID => Http::response([
                'id' => self::ISSUE_ID, 'status' => 'blocked',
            ], 200),
            'paperclip-test.local/api/companies/company-test/activity*' => Http::response([], 200),
            'paperclip-test.local/api/issues/'.self::ISSUE_ID.'/comments' => Http::response([
                ['body' => 'Нужен доступ к базе данных. @DBA пожалуйста помоги.'],
            ], 200),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun();
        $run->update(['paperclip_issue_id' => self::ISSUE_ID, 'status' => 'processing']);

        $this->app->call([new CheckPaperclipIssueStatusJob($run->id, 0, 0), 'handle']);

        $run  = $run->fresh();
        $task = $task->fresh();

        $this->assertSame(AgentTaskRunStatus::PAUSED->value, $run->status->value);
        $this->assertSame('Нужен доступ к базе данных. @DBA пожалуйста помоги.', $run->error_message);
        $this->assertNotNull($run->finished_at);

        $this->assertFalse($task->enabled);
        $this->assertNull($task->locked_at);
        $this->assertNotNull($task->last_failed_at);
        $this->assertSame('Нужен доступ к базе данных. @DBA пожалуйста помоги.', $task->last_error);
    }

    #[Test]
    public function check_job_uses_default_blocked_reason_when_no_comments(): void
    {
        Http::fake([
            'paperclip-test.local/api/issues/'.self::ISSUE_ID => Http::response([
                'id' => self::ISSUE_ID, 'status' => 'blocked',
            ], 200),
            'paperclip-test.local/api/companies/company-test/activity*' => Http::response([], 200),
            'paperclip-test.local/api/issues/'.self::ISSUE_ID.'/comments' => Http::response([], 200),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun();
        $run->update(['paperclip_issue_id' => self::ISSUE_ID, 'status' => 'processing']);

        $this->app->call([new CheckPaperclipIssueStatusJob($run->id, 0, 0), 'handle']);

        $run = $run->fresh();
        $this->assertSame(AgentTaskRunStatus::PAUSED->value, $run->status->value);
        $this->assertSame('Задача заблокирована в Paperclip.', $run->error_message);
    }

    #[Test]
    public function check_job_pauses_run_and_does_not_throw_when_telegram_chat_id_configured(): void
    {
        Http::fake([
            'paperclip-test.local/api/issues/'.self::ISSUE_ID => Http::response([
                'id' => self::ISSUE_ID, 'status' => 'blocked',
            ], 200),
            'paperclip-test.local/api/companies/company-test/activity*' => Http::response([], 200),
            'paperclip-test.local/api/issues/'.self::ISSUE_ID.'/comments' => Http::response([
                ['body' => 'Blocked: need approval.'],
            ], 200),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun();
        // notification_telegram_chat_id set — Telegram delivery will silently fail (no token in test env)
        // but the run must still be paused without throwing
        $task->update(['notification_telegram_chat_id' => 99991234]);
        $run->update(['paperclip_issue_id' => self::ISSUE_ID, 'status' => 'processing']);

        $this->app->call([new CheckPaperclipIssueStatusJob($run->id, 0, 0), 'handle']);

        $run  = $run->fresh();
        $task = $task->fresh();

        $this->assertSame(AgentTaskRunStatus::PAUSED->value, $run->status->value);
        $this->assertSame('Blocked: need approval.', $run->error_message);
        $this->assertFalse($task->enabled);
        $this->assertNull($task->locked_at);
    }

    #[Test]
    public function check_job_pauses_run_when_blocked_even_if_comments_api_fails(): void
    {
        Http::fake([
            'paperclip-test.local/api/issues/'.self::ISSUE_ID => Http::response([
                'id' => self::ISSUE_ID, 'status' => 'blocked',
            ], 200),
            'paperclip-test.local/api/companies/company-test/activity*' => Http::response([], 200),
            'paperclip-test.local/api/issues/'.self::ISSUE_ID.'/comments' => Http::response([
                'error' => 'Internal Server Error',
            ], 500),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun();
        $run->update(['paperclip_issue_id' => self::ISSUE_ID, 'status' => 'processing']);

        $this->app->call([new CheckPaperclipIssueStatusJob($run->id, 0, 0), 'handle']);

        $run = $run->fresh();
        $this->assertSame(AgentTaskRunStatus::PAUSED->value, $run->status->value);
        $this->assertSame('Задача заблокирована в Paperclip.', $run->error_message);
    }

    // -------------------------------------------------------------------------

    private function createPaperclipTaskAndRun(string $name = 'Paperclip task', string $prompt = 'Do something'): array
    {
        $user = User::factory()->create();

        $task = AgentTask::create([
            'user_id'         => $user->id,
            'name'            => $name,
            'prompt'          => $prompt,
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
            'agent_task_id' => $task->id,
            'status'        => 'queued',
            'scheduled_for' => now()->subMinute(),
        ]);

        return [$task, $run];
    }
}
