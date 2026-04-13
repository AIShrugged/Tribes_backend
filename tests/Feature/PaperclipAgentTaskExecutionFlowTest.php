<?php

namespace Tests\Feature;

use App\Enums\AgentTaskRunStatus;
use App\Jobs\RunAgentTaskJob;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
            'paperclip.api_url'              => 'http://paperclip-test.local',
            'paperclip.api_key'              => 'test-api-key',
            'paperclip.company_id'           => 'company-test',
            'paperclip.agent_id'             => 'agent-test',
            'paperclip.polling.intervals'    => [0],  // no sleep during tests
            'paperclip.polling.max_seconds'  => 5,
        ]);
    }

    #[Test]
    public function paperclip_task_completes_and_stores_output_from_plan_document(): void
    {
        Http::fake([
            'paperclip-test.local/api/companies/company-test/issues' => Http::response([
                'id'     => self::ISSUE_ID,
                'status' => 'todo',
            ], 201),

            'paperclip-test.local/api/issues/'.self::ISSUE_ID => Http::sequence()
                ->push(['id' => self::ISSUE_ID, 'status' => 'in_progress'], 200)
                ->push(['id' => self::ISSUE_ID, 'status' => 'done', 'planDocument' => 'The task is done. Here is the result.'], 200),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun('Summarise last week');

        $job = new RunAgentTaskJob($task->id, $run->id, 1);
        $this->app->call([$job, 'handle']);

        $run  = $run->fresh();
        $task = $task->fresh();

        $this->assertSame(AgentTaskRunStatus::COMPLETED->value, $run->status->value);
        $this->assertSame('The task is done. Here is the result.', $run->output);
        $this->assertSame(self::ISSUE_ID, $run->paperclip_issue_id);
        $this->assertSame(self::ISSUE_ID, $run->metadata['paperclip_issue_id']);
        $this->assertFalse($task->enabled);
        $this->assertNull($task->locked_at);
        $this->assertNotNull($task->last_completed_at);
    }

    #[Test]
    public function paperclip_task_completes_and_stores_output_from_last_comment_when_no_plan_document(): void
    {
        Http::fake([
            'paperclip-test.local/api/companies/company-test/issues' => Http::response([
                'id'     => self::ISSUE_ID,
                'status' => 'todo',
            ], 201),

            'paperclip-test.local/api/issues/'.self::ISSUE_ID => Http::response([
                'id'          => self::ISSUE_ID,
                'status'      => 'done',
                'planDocument' => null,
            ], 200),

            'paperclip-test.local/api/issues/'.self::ISSUE_ID.'/comments' => Http::response([
                ['body' => 'First comment'],
                ['body' => 'Final result comment'],
            ], 200),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun('Write a summary');

        $job = new RunAgentTaskJob($task->id, $run->id, 1);
        $this->app->call([$job, 'handle']);

        $run = $run->fresh();

        $this->assertSame(AgentTaskRunStatus::COMPLETED->value, $run->status->value);
        $this->assertSame('Final result comment', $run->output);
        $this->assertSame(self::ISSUE_ID, $run->paperclip_issue_id);
    }

    #[Test]
    public function paperclip_task_fails_when_issue_is_cancelled(): void
    {
        Http::fake([
            'paperclip-test.local/api/companies/company-test/issues' => Http::response([
                'id'     => self::ISSUE_ID,
                'status' => 'todo',
            ], 201),

            'paperclip-test.local/api/issues/'.self::ISSUE_ID => Http::response([
                'id'     => self::ISSUE_ID,
                'status' => 'cancelled',
            ], 200),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun('Task that will be cancelled');

        $job = new RunAgentTaskJob($task->id, $run->id, 1);
        try {
            $this->app->call([$job, 'handle']);
        } catch (\RuntimeException) {
            // job re-throws after updating run/task state
        }

        $run  = $run->fresh();
        $task = $task->fresh();

        $this->assertSame(AgentTaskRunStatus::FAILED->value, $run->status->value);
        $this->assertStringContainsString('cancelled', $run->error_message);
        $this->assertSame(self::ISSUE_ID, $run->paperclip_issue_id);
        $this->assertNull($task->locked_at);
        $this->assertNotNull($task->last_failed_at);
    }

    #[Test]
    public function paperclip_task_fails_on_polling_timeout(): void
    {
        config(['paperclip.polling.max_seconds' => 0]);  // timeout immediately

        Http::fake([
            'paperclip-test.local/api/companies/company-test/issues' => Http::response([
                'id'     => self::ISSUE_ID,
                'status' => 'todo',
            ], 201),

            'paperclip-test.local/api/issues/'.self::ISSUE_ID => Http::response([
                'id'     => self::ISSUE_ID,
                'status' => 'in_progress',
            ], 200),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun('Long running task');

        $job = new RunAgentTaskJob($task->id, $run->id, 1);
        try {
            $this->app->call([$job, 'handle']);
        } catch (\RuntimeException) {
            // job re-throws after updating run/task state
        }

        $run = $run->fresh();

        $this->assertSame(AgentTaskRunStatus::FAILED->value, $run->status->value);
        $this->assertStringContainsString('timeout', $run->error_message);
    }

    #[Test]
    public function paperclip_task_fails_when_api_returns_error(): void
    {
        Http::fake([
            'paperclip-test.local/api/companies/company-test/issues' => Http::response([
                'error' => 'Unauthorized',
            ], 401),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun('Task with bad credentials');

        $job = new RunAgentTaskJob($task->id, $run->id, 1);
        try {
            $this->app->call([$job, 'handle']);
        } catch (\RuntimeException) {
            // job re-throws after updating run/task state
        }

        $run = $run->fresh();

        $this->assertSame(AgentTaskRunStatus::FAILED->value, $run->status->value);
        $this->assertStringContainsString('401', $run->error_message);
    }

    #[Test]
    public function paperclip_issue_is_created_with_task_prompt_as_description(): void
    {
        Http::fake([
            'paperclip-test.local/api/companies/company-test/issues' => Http::response([
                'id'     => self::ISSUE_ID,
                'status' => 'todo',
            ], 201),

            'paperclip-test.local/api/issues/'.self::ISSUE_ID => Http::response([
                'id'          => self::ISSUE_ID,
                'status'      => 'done',
                'planDocument' => 'done',
            ], 200),
        ]);

        [$task, $run] = $this->createPaperclipTaskAndRun(
            name: 'My task',
            prompt: 'Do something important',
        );

        $job = new RunAgentTaskJob($task->id, $run->id, 1);
        $this->app->call([$job, 'handle']);

        Http::assertSent(function (Request $request) use ($task) {
            if (! str_contains($request->url(), '/api/companies/company-test/issues')) {
                return false;
            }

            $body = $request->data();

            return $body['title'] === $task->name
                && str_contains($body['description'], $task->prompt)
                && $body['assigneeAgentId'] === 'agent-test'
                && $body['status'] === 'todo';
        });
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
