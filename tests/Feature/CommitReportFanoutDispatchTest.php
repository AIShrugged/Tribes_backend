<?php

namespace Tests\Feature;

use App\Jobs\RunAgentTaskJob;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\CommitReport;
use App\Models\CommitReportItem;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\User;
use App\Services\AgentTaskSchedulerService;
use App\Services\CommitReport\CommitReportService;
use App\Services\CommitReport\CommitReviewFanoutService;
use Database\Seeders\CommitReviewerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CommitReportFanoutDispatchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private AgentTask $task;

    private AgentTaskRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        // Allow dispatch under the test sync queue (Queue::fake intercepts the job anyway).
        config(['agent.commit_report.review.skip_under_sync' => false]);
        $this->seed(CommitReviewerSeeder::class);

        $this->org = Organization::create(['name' => 'F', 'slug' => 'fan-org']);
        $user = User::factory()->create();
        $this->task = AgentTask::create([
            'user_id' => $user->id, 'organization_id' => $this->org->id,
            'agent_task_type' => 'background', 'execution_mode' => 'inline',
            'schedule_type' => 'interval', 'interval_seconds' => 86400, 'enabled' => true,
            'name' => 'Commit Reporter', 'prompt' => 'x',
            'metadata' => ['kind' => 'commit_report'],
        ]);
        $this->run = $this->task->runs()->create(['status' => 'completed', 'attempt' => 1, 'scheduled_for' => now()]);
    }

    private function sha(string $s): string
    {
        return str_pad($s, 40, '0');
    }

    private function seedReport(array $items): CommitReport
    {
        return app(CommitReportService::class)->save([
            'repo' => 'AIShrugged/Tribes_backend', 'branch' => 'dev',
            'period_start' => '2026-06-15', 'period_end' => '2026-06-15', 'summary' => 'x',
            'items' => ['added' => $items, 'fixed' => [], 'skipped' => []],
            'organization_id' => $this->org->id,
            'generated_by_agent_task_run_id' => $this->run->id,
        ]);
    }

    private function issue(string $name): Issue
    {
        return Issue::create(['name' => $name, 'organization_id' => $this->org->id, 'status' => 'open']);
    }

    private function fanout(): CommitReviewFanoutService
    {
        return app(CommitReviewFanoutService::class);
    }

    #[Test]
    public function dispatches_one_subrun_per_matched_pending_item(): void
    {
        Queue::fake();
        $report = $this->seedReport([
            ['sha' => $this->sha('a'), 'title' => 't', 'summary' => 's', 'matched_issue_id' => $this->issue('i1')->id],
            ['sha' => $this->sha('b'), 'title' => 't', 'summary' => 's', 'matched_issue_id' => $this->issue('i2')->id],
            ['sha' => $this->sha('c'), 'title' => 't', 'summary' => 's'], // unmatched -> not flagged, no dispatch
        ]);

        $this->fanout()->maybeDispatch($this->task, $this->run);

        Queue::assertPushed(RunAgentTaskJob::class, 2);
        $subs = AgentTask::where('parent_agent_task_id', $this->task->id)->get();
        $this->assertCount(2, $subs);
        $this->assertSame($this->run->id, $subs->first()->origin_agent_task_run_id);
        $this->assertSame('commit_review', $subs->first()->metadata['kind']);
        $this->assertSame($report->id, $subs->first()->input_payload['commit_report_id']);

        $this->assertSame('in_progress', CommitReportItem::where('commit_report_id', $report->id)->where('sha', $this->sha('a'))->value('review_status'));
        $this->assertSame('not_flagged', CommitReportItem::where('commit_report_id', $report->id)->where('sha', $this->sha('c'))->value('review_status'));
    }

    #[Test]
    public function respects_the_cap_and_marks_overflow_deferred(): void
    {
        Queue::fake();
        config(['agent.commit_report.review.max_subruns_per_report' => 1]);
        $report = $this->seedReport([
            ['sha' => $this->sha('a'), 'title' => 't', 'summary' => 's', 'matched_issue_id' => $this->issue('i1')->id],
            ['sha' => $this->sha('b'), 'title' => 't', 'summary' => 's', 'matched_issue_id' => $this->issue('i2')->id],
        ]);

        $this->fanout()->maybeDispatch($this->task, $this->run);

        Queue::assertPushed(RunAgentTaskJob::class, 1);
        $statuses = CommitReportItem::where('commit_report_id', $report->id)->pluck('review_status')->all();
        $this->assertContains('in_progress', $statuses);
        $this->assertContains('deferred', $statuses);
    }

    #[Test]
    public function noop_for_a_non_commit_report_task(): void
    {
        Queue::fake();
        $this->task->update(['metadata' => ['kind' => 'something_else']]);
        $this->seedReport([['sha' => $this->sha('a'), 'title' => 't', 'summary' => 's', 'matched_issue_id' => $this->issue('i')->id]]);

        $this->fanout()->maybeDispatch($this->task, $this->run);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function skips_dispatch_under_sync_queue_when_configured(): void
    {
        Queue::fake();
        config(['agent.commit_report.review.skip_under_sync' => true, 'queue.default' => 'sync']);
        $this->seedReport([['sha' => $this->sha('a'), 'title' => 't', 'summary' => 's', 'matched_issue_id' => $this->issue('i')->id]]);

        $this->fanout()->maybeDispatch($this->task, $this->run);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_dispatch_failure_leaves_the_item_pending_and_does_not_abort_the_fanout(): void
    {
        Queue::fake();
        $this->app->bind(AgentTaskSchedulerService::class, fn () => new class extends AgentTaskSchedulerService
        {
            public function dispatchTaskNow(AgentTask $task, bool $force = false)
            {
                throw new \RuntimeException('broker down');
            }
        });

        $report = $this->seedReport([
            ['sha' => $this->sha('a'), 'title' => 't', 'summary' => 's', 'matched_issue_id' => $this->issue('i1')->id],
            ['sha' => $this->sha('b'), 'title' => 't', 'summary' => 's', 'matched_issue_id' => $this->issue('i2')->id],
        ]);

        // must not throw, despite every dispatch failing
        $this->fanout()->maybeDispatch($this->task, $this->run);

        // both items stay pending (not stranded in_progress) → a later run can re-dispatch them
        $statuses = CommitReportItem::where('commit_report_id', $report->id)->pluck('review_status')->all();
        $this->assertCount(2, $statuses);
        $this->assertSame(['pending'], array_values(array_unique($statuses)));
    }
}
