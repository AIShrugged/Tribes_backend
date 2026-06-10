<?php

namespace Tests\Feature;

use App\Jobs\ProcessTaskDataUploadJob;
use App\Jobs\SendTaskDataUploadReportJob;
use App\Models\ExtractionPlan;
use App\Models\Issue;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\TaskDataUpload;
use App\Models\Team;
use App\Models\User;
use App\Services\ExtractionPlan\ApproveExtractionPlanService;
use App\Services\OpenRouterClient;
use App\Services\TaskData\TaskDataIssueExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gated (flag ON) pre-moderation flow for the task-data upload path: extract → stage (no DB writes,
 * no рассылка) → approve (issues + downstream) / reject (nothing). Proves the feature works AND that
 * the flag-off path is unchanged.
 */
class ExtractionPlanTaskDataTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Team $team;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Mod Org', 'slug' => 'mod-org']);
        $this->user = User::factory()->create();
        $this->org->users()->attach($this->user, ['role' => 'employee']);

        $methodology = Methodology::create([
            'name' => 'M', 'text' => 'M.', 'scheme' => json_encode(['type' => 'object']),
            'organization_id' => $this->org->id,
        ]);
        $this->team = Team::create([
            'name' => 'Mod Team', 'slug' => 'mod-team',
            'organization_id' => $this->org->id, 'methodology_id' => $methodology->id,
        ]);
        $this->team->users()->attach($this->user);
    }

    private function mockExtraction(array $issues): void
    {
        $mock = Mockery::mock(OpenRouterClient::class);
        $mock->shouldReceive('chat')->andReturn(json_encode(['is_work_relevant' => true, 'issues' => $issues]));
        $this->app->instance(OpenRouterClient::class, $mock);
    }

    private function makeUpload(): TaskDataUpload
    {
        return TaskDataUpload::create([
            'user_id' => $this->user->id,
            'team_id' => $this->team->id,
            'organization_id' => $this->org->id,
            'original_filename' => 'tasks.txt',
            'status' => 'queued',
        ]);
    }

    private function runJob(TaskDataUpload $upload): void
    {
        (new ProcessTaskDataUploadJob($upload->id, 'some task content'))
            ->handle($this->app->make(TaskDataIssueExtractionService::class));
    }

    #[Test]
    public function gated_upload_stages_a_plan_without_writing_or_notifying(): void
    {
        Queue::fake();
        $this->mockExtraction([
            ['name' => 'Ship feature', 'description' => 'd', 'type' => 'backend'],
            ['name' => 'Write docs', 'description' => 'd', 'type' => 'organization'],
        ]);

        $upload = $this->makeUpload();
        $this->runJob($upload);

        $this->assertSame('pending_review', $upload->fresh()->status);
        $this->assertDatabaseCount('issues', 0);

        $plan = ExtractionPlan::where('sourceable_type', TaskDataUpload::class)
            ->where('sourceable_id', $upload->id)->firstOrFail();
        $this->assertSame('pending_review', $plan->status);
        $this->assertCount(2, $plan->plan['issues']['items']);

        Queue::assertNotPushed(SendTaskDataUploadReportJob::class);
    }

    #[Test]
    public function telegram_origin_upload_persists_immediately_with_no_plan(): void
    {
        // Telegram-thread uploads have no dashboard reviewer and expect an immediate chat report —
        // they are structurally excluded from moderation (the analog of Recall for transcripts).
        Queue::fake();
        $this->mockExtraction([['name' => 'Ship feature', 'description' => 'd', 'type' => 'backend']]);

        $upload = $this->makeUpload();
        $upload->update(['source_telegram_chat_id' => 555000111]);
        $this->runJob($upload);

        $this->assertSame('done', $upload->fresh()->status);
        $this->assertDatabaseCount('issues', 1);
        $this->assertDatabaseCount('extraction_plans', 0);
        Queue::assertPushed(SendTaskDataUploadReportJob::class);
    }

    #[Test]
    public function approve_materializes_issues_and_replays_downstream(): void
    {
        Queue::fake();
        $this->mockExtraction([
            ['name' => 'Ship feature', 'description' => 'd', 'type' => 'backend'],
            ['name' => 'Write docs', 'description' => 'd', 'type' => 'organization'],
        ]);

        $upload = $this->makeUpload();
        $this->runJob($upload);

        $plan = ExtractionPlan::where('sourceable_id', $upload->id)->firstOrFail();
        $result = $this->app->make(ApproveExtractionPlanService::class)->approve($plan);

        $this->assertSame(2, $result['issues_created']);
        $this->assertDatabaseCount('issues', 2);
        $this->assertSame('done', $upload->fresh()->status);
        $this->assertSame('approved', $plan->fresh()->status);
        Queue::assertPushed(SendTaskDataUploadReportJob::class);
    }

    #[Test]
    public function double_approve_is_blocked_and_does_not_duplicate(): void
    {
        Queue::fake();
        $this->mockExtraction([['name' => 'Ship feature', 'description' => 'd', 'type' => 'backend']]);

        $upload = $this->makeUpload();
        $this->runJob($upload);
        $plan = ExtractionPlan::where('sourceable_id', $upload->id)->firstOrFail();

        $this->app->make(ApproveExtractionPlanService::class)->approve($plan);
        $this->assertDatabaseCount('issues', 1);

        try {
            $this->app->make(ApproveExtractionPlanService::class)->approve($plan->fresh());
            $this->fail('Second approve should have thrown');
        } catch (\App\Exceptions\AppException $e) {
            // expected
        }

        $this->assertDatabaseCount('issues', 1); // no duplication
    }

    #[Test]
    public function reject_writes_nothing(): void
    {
        Queue::fake();
        $this->mockExtraction([['name' => 'Ship feature', 'description' => 'd', 'type' => 'backend']]);

        $upload = $this->makeUpload();
        $this->runJob($upload);
        $plan = ExtractionPlan::where('sourceable_id', $upload->id)->firstOrFail();

        $plan->update(['status' => ExtractionPlan::STATUS_REJECTED]);
        TaskDataUpload::where('id', $upload->id)->update(['status' => 'rejected']);

        $this->assertDatabaseCount('issues', 0);
        $this->assertSame('rejected', $plan->fresh()->status);
        $this->assertSame('rejected', $upload->fresh()->status);
    }

    #[Test]
    public function editing_an_update_issue_propagates_the_edit_to_the_existing_issue(): void
    {
        // Regression: applyDecisions reads update fields from the DECISION, not the item, so an edit
        // to an "Updates #X" row must be propagated into the decision (else it's silently lost).
        Queue::fake();

        $existing = Issue::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->org->id,
            'team_id' => $this->team->id,
            'name' => 'Existing task',
            'status' => 'open',
            'type' => 'development',
            'priority' => 0,
        ]);

        // Two LLM calls during staging: Pass-1 extract, then the merge that decides UPDATE.
        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('chat')->andReturn(
            json_encode(['is_work_relevant' => true, 'issues' => [
                ['name' => 'Existing task', 'description' => 'more detail', 'type' => 'backend'],
            ]]),
            json_encode(['decisions' => [
                ['index' => 0, 'action' => 'update', 'existing_issue_id' => $existing->id, 'assignee_name' => 'LLM Guy'],
            ]]),
        );
        $this->app->instance(OpenRouterClient::class, $llm);

        $upload = $this->makeUpload();
        $this->runJob($upload);
        $plan = ExtractionPlan::where('sourceable_id', $upload->id)->firstOrFail();

        // User edits the assignee on the update row.
        $this->actingAs($this->user)
            ->patchJson("/api/v1/uploads/task_data/{$upload->id}/plan", [
                'issues' => [['uid' => 'i-0', 'assignee_name' => 'Edited Person']],
            ])
            ->assertOk();

        $this->app->make(ApproveExtractionPlanService::class)->approve($plan->fresh());

        // The existing issue must carry the EDITED assignee, not the LLM's original.
        $this->assertSame('Edited Person', $existing->fresh()->assignee_name);
        $this->assertDatabaseCount('issues', 1); // updated, not duplicated
    }
}
