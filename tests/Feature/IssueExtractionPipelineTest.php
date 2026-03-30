<?php

namespace Tests\Feature;

use App\Enums\MeetingTaskStatus;
use App\Events\IssuesExtracted;
use App\Events\TranscriptParsed;
use App\Jobs\ExtractIssuesFromTranscriptJob;
use App\Models\AgentTask;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Source;
use App\Models\Team;
use App\Models\TranscriptEntry;
use App\Models\User;
use App\Services\IssueExtractionService;
use App\Services\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IssueExtractionPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Organization $organization;
    protected Team $team;
    protected CalendarEvent $calendarEvent;
    protected Methodology $methodology;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
        ]);

        $this->user = User::factory()->create();
        $this->organization->users()->attach($this->user, ['role' => 'employee']);

        $this->methodology = Methodology::create([
            'name' => 'Test Methodology',
            'text' => 'Analyze the meeting.',
            'scheme' => json_encode(['type' => 'object']),
            'organization_id' => $this->organization->id,
        ]);

        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'organization_id' => $this->organization->id,
            'methodology_id' => $this->methodology->id,
        ]);
        $this->team->users()->attach($this->user);

        $source = Source::create([
            'user_id' => $this->user->id,
            'type' => 'google_calendar',
            'external_id' => 'test-source-id',
            'identity' => 'test@example.com',
        ]);

        $this->calendarEvent = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'test-event-id',
            'platform' => 'google_meet',
            'title' => 'Sprint Review Meeting',
            'url' => 'https://meet.google.com/test',
            'description' => 'Sprint review',
            'starts_at' => now(),
            'ends_at' => now()->addHour(),
            'required_bot' => false,
        ]);

        $this->seedTranscript();
    }

    private function seedTranscript(): void
    {
        $john = Participant::create([
            'calendar_event_id' => $this->calendarEvent->id,
            'name' => 'John Doe',
        ]);

        $jane = Participant::create([
            'calendar_event_id' => $this->calendarEvent->id,
            'name' => 'Jane Smith',
        ]);

        $entries = [
            [$john, 'Привет всем, давайте обсудим итоги спринта.', 0.0, 5.0],
            [$jane, 'Привет! У нас баг в авторизации — при логине через Google OAuth не сохраняется сессия.', 5.0, 12.0],
            [$john, 'Это критично, нужно исправить в первую очередь. Джейн, возьмёшь на себя?', 12.0, 18.0],
            [$jane, 'Да, я посмотрю. Ещё нужно добавить экспорт отчётов в PDF, клиенты просят.', 18.0, 25.0],
            [$john, 'Согласен, давай заведём задачу на это.', 25.0, 30.0],
        ];

        $baseTime = now();
        foreach ($entries as [$participant, $text, $start, $end]) {
            TranscriptEntry::create([
                'calendar_event_id' => $this->calendarEvent->id,
                'participant_id' => $participant->id,
                'text' => $text,
                'start_relative' => $start,
                'end_relative' => $end,
                'start_absolute' => $baseTime->copy()->addSeconds((int) $start),
                'end_absolute' => $baseTime->copy()->addSeconds((int) $end),
            ]);
        }
    }

    // ── 1. TranscriptParsed dispatches extraction job ──

    #[Test]
    public function transcript_parsed_event_dispatches_extraction_job(): void
    {
        Queue::fake();

        Event::dispatch(new TranscriptParsed($this->calendarEvent));

        Queue::assertPushed(ExtractIssuesFromTranscriptJob::class, function ($job) {
            return $job->calendarEvent->id === $this->calendarEvent->id
                && $job->team->id === $this->team->id
                && $job->user->id === $this->user->id;
        });
    }

    #[Test]
    public function transcript_parsed_does_not_dispatch_extraction_if_user_has_no_teams(): void
    {
        Queue::fake();

        $this->team->users()->detach($this->user);

        Event::dispatch(new TranscriptParsed($this->calendarEvent));

        Queue::assertNotPushed(ExtractIssuesFromTranscriptJob::class);
    }

    // ── 2. IssueExtractionService extracts issues ──

    #[Test]
    public function extraction_service_creates_issues_from_transcript(): void
    {
        $service = $this->app->make(IssueExtractionService::class);
        $issues = $service->extract($this->calendarEvent, $this->team, $this->user);

        $this->assertCount(2, $issues);

        $development = $issues->firstWhere('type', 'development');
        $this->assertNotNull($development);
        $this->assertEquals('Исправить баг в авторизации', $development->name);
        $this->assertEquals('John Doe', $development->assignee_name);
        $this->assertEquals(MeetingTaskStatus::OPEN->value, $development->status);
        $this->assertEquals($this->calendarEvent->id, $development->sourceable_id);
        $this->assertEquals(CalendarEvent::class, $development->sourceable_type);
        $this->assertEquals($this->organization->id, $development->organization_id);
        $this->assertEquals($this->team->id, $development->team_id);

        $organization = $issues->firstWhere('type', 'organization');
        $this->assertNotNull($organization);
        $this->assertEquals('Добавить экспорт отчётов в PDF', $organization->name);
        $this->assertNull($organization->assignee_name);
    }

    #[Test]
    public function extraction_service_returns_empty_collection_for_empty_transcript(): void
    {
        // Удаляем все записи транскрипта
        TranscriptEntry::where('calendar_event_id', $this->calendarEvent->id)->delete();

        $service = $this->app->make(IssueExtractionService::class);
        $issues = $service->extract($this->calendarEvent, $this->team, $this->user);

        $this->assertCount(0, $issues);
    }

    #[Test]
    public function extraction_service_handles_llm_failure_gracefully(): void
    {
        $mockClient = Mockery::mock(OpenRouterClient::class);
        $mockClient->shouldReceive('chat')
            ->once()
            ->andThrow(new \Exception('API Error'));
        $this->app->instance(OpenRouterClient::class, $mockClient);

        $service = $this->app->make(IssueExtractionService::class);
        $issues = $service->extract($this->calendarEvent, $this->team, $this->user);

        $this->assertCount(0, $issues);
        $this->assertDatabaseCount('issues', 0);
    }

    // ── 3. ExtractIssuesFromTranscriptJob fires IssuesExtracted event ──

    #[Test]
    public function extraction_job_fires_issues_extracted_event(): void
    {
        Event::fake([IssuesExtracted::class]);

        $job = new ExtractIssuesFromTranscriptJob($this->calendarEvent, $this->team, $this->user);
        $job->handle($this->app->make(IssueExtractionService::class));

        Event::assertDispatched(IssuesExtracted::class, function ($event) {
            return $event->issues->count() === 2
                && $event->team->id === $this->team->id
                && $event->user->id === $this->user->id;
        });
    }

    #[Test]
    public function extraction_job_does_not_fire_event_when_no_issues(): void
    {
        Event::fake([IssuesExtracted::class]);

        TranscriptEntry::where('calendar_event_id', $this->calendarEvent->id)->delete();

        $job = new ExtractIssuesFromTranscriptJob($this->calendarEvent, $this->team, $this->user);
        $job->handle($this->app->make(IssueExtractionService::class));

        Event::assertNotDispatched(IssuesExtracted::class);
    }

    // ── 4. DispatchAgentTasksForIssues creates agent tasks ──

    #[Test]
    public function issues_extracted_event_creates_agent_tasks(): void
    {
        Queue::fake();

        $service = $this->app->make(IssueExtractionService::class);
        $issues = $service->extract($this->calendarEvent, $this->team, $this->user);

        IssuesExtracted::dispatch($issues, $this->team, $this->user);

        $this->assertDatabaseCount('agent_tasks', 2);

        foreach ($issues as $issue) {
            $issue->refresh();
            $this->assertNotNull($issue->agent_task_id);

            $agentTask = AgentTask::find($issue->agent_task_id);
            $this->assertNotNull($agentTask);
            $this->assertEquals($this->user->id, $agentTask->user_id);
            $this->assertEquals($this->team->id, $agentTask->team_id);
            $this->assertEquals($issue->id, $agentTask->metadata['issue_id']);
            $this->assertTrue($agentTask->metadata['auto_dispatched']);
        }
    }

    // ── 5. Issue status transitions ──

    #[Test]
    public function issue_supports_review_and_reopen_statuses(): void
    {
        Queue::fake();

        $issue = Issue::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'team_id' => $this->team->id,
            'name' => 'Test issue',
            'type' => 'organization',
            'status' => MeetingTaskStatus::OPEN->value,
        ]);

        $issue->update(['status' => MeetingTaskStatus::REVIEW->value]);
        $this->assertEquals('review', $issue->fresh()->status);

        $issue->update(['status' => MeetingTaskStatus::REOPEN->value]);
        $this->assertEquals('reopen', $issue->fresh()->status);

        $issue->update(['status' => MeetingTaskStatus::DONE->value]);
        $this->assertEquals('done', $issue->fresh()->status);
        $this->assertNotNull($issue->fresh()->close_date);
    }

    // ── 6. IssueObserver reopen triggers agent task ──

    #[Test]
    public function reopen_status_dispatches_agent_task_for_rework(): void
    {
        Queue::fake();

        $issue = Issue::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'team_id' => $this->team->id,
            'name' => 'Fix login bug',
            'type' => 'development',
            'status' => MeetingTaskStatus::REVIEW->value,
            'pr_url' => 'https://github.com/org/repo/pull/42',
            'pr_number' => 42,
            'pr_repository' => 'org/repo',
        ]);

        $issue->update(['status' => MeetingTaskStatus::REOPEN->value]);

        $issue->refresh();
        $this->assertNotNull($issue->agent_task_id);

        $agentTask = AgentTask::find($issue->agent_task_id);
        $this->assertNotNull($agentTask);
        $this->assertEquals($issue->id, $agentTask->metadata['issue_id']);
        $this->assertTrue($agentTask->metadata['reopen']);
        $this->assertStringContains('github_get_pull_request_comments', $agentTask->prompt);
        $this->assertStringContains('org/repo', $agentTask->prompt);
    }

    #[Test]
    public function reopen_without_pr_still_creates_agent_task(): void
    {
        Queue::fake();

        $issue = Issue::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'team_id' => $this->team->id,
            'name' => 'Non-code task',
            'type' => 'organization',
            'status' => MeetingTaskStatus::REVIEW->value,
        ]);

        $issue->update(['status' => MeetingTaskStatus::REOPEN->value]);

        $issue->refresh();
        $this->assertNotNull($issue->agent_task_id);

        $agentTask = AgentTask::find($issue->agent_task_id);
        $this->assertStringNotContains('github_get_pull_request_comments', $agentTask->prompt);
    }

    #[Test]
    public function non_reopen_status_change_does_not_create_agent_task(): void
    {
        Queue::fake();

        $issue = Issue::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'team_id' => $this->team->id,
            'name' => 'Regular task',
            'type' => 'organization',
            'status' => MeetingTaskStatus::OPEN->value,
        ]);

        $issue->update(['status' => MeetingTaskStatus::IN_PROGRESS->value]);
        $this->assertNull($issue->fresh()->agent_task_id);

        $issue->update(['status' => MeetingTaskStatus::DONE->value]);
        $this->assertNull($issue->fresh()->agent_task_id);
    }

    // ── 7. Full pipeline integration ──

    #[Test]
    public function full_pipeline_from_transcript_to_agent_tasks(): void
    {
        Queue::fake();

        // Шаг 1: Экстракция issues из транскрипта
        $service = $this->app->make(IssueExtractionService::class);
        $issues = $service->extract($this->calendarEvent, $this->team, $this->user);
        $this->assertCount(2, $issues);

        // Шаг 2: Диспатч события → создание agent tasks
        IssuesExtracted::dispatch($issues, $this->team, $this->user);

        // Проверяем: 2 issues в базе, 2 agent tasks, все связаны
        $this->assertDatabaseCount('issues', 2);
        $this->assertDatabaseCount('agent_tasks', 2);

        foreach (Issue::all() as $issue) {
            $this->assertNotNull($issue->agent_task_id);
            $this->assertEquals(MeetingTaskStatus::OPEN->value, $issue->status);
            $this->assertEquals($this->calendarEvent->id, $issue->sourceable_id);
        }

        // Шаг 3: Симулируем агент поставил review
        $issue = Issue::first();
        $issue->update([
            'status' => MeetingTaskStatus::REVIEW->value,
            'pr_url' => 'https://github.com/org/repo/pull/1',
            'pr_number' => 1,
            'pr_repository' => 'org/repo',
        ]);
        $this->assertEquals('review', $issue->fresh()->status);

        // Шаг 4: Пользователь делает reopen → Observer создаёт новый agent task
        $oldAgentTaskId = $issue->agent_task_id;
        $issue->update(['status' => MeetingTaskStatus::REOPEN->value]);

        $issue->refresh();
        $this->assertNotEquals($oldAgentTaskId, $issue->agent_task_id);
        $this->assertDatabaseCount('agent_tasks', 3); // 2 из экстракции + 1 из reopen
    }

    // ── Helpers ──

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'."
        );
    }

    private function assertStringNotContains(string $needle, string $haystack): void
    {
        $this->assertFalse(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' does not contain '{$needle}'."
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
