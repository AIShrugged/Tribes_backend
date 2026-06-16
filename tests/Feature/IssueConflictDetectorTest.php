<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\IssueConflict;
use App\Models\MeetingSummary;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Source;
use App\Models\Team;
use App\Models\User;
use App\Services\Issue\IssueConflictDetector;
use App\Services\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IssueConflictDetectorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Team $team;
    private User $author;
    private CalendarEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Conf Org', 'slug' => 'conf-org']);
        $this->author = User::factory()->create();
        $methodology = Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create(['name' => 'Default Methodology', 'text' => 'Default methodology text', 'scheme' => '{}', 'is_default' => true]);
        $this->team = Team::create([
            'name' => 'Conf Team',
            'slug' => 'conf-team',
            'organization_id' => $this->org->id,
            'methodology_id' => $methodology->id,
        ]);
        $this->team->users()->attach($this->author);

        $source = Source::create([
            'user_id'     => $this->author->id,
            'type'        => 'google_calendar',
            'external_id' => 'conf-src',
            'identity'    => 'a@example.com',
        ]);
        $this->event = CalendarEvent::create([
            'source_id'    => $source->id,
            'external_id'  => 'conf-event',
            'platform'     => 'google_meet',
            'title'        => 'Planning',
            'description'  => '',
            'url'          => 'https://meet.google.com/c',
            'starts_at'    => now(),
            'ends_at'      => now()->addHour(),
            'required_bot' => false,
        ]);
    }

    private function makeTask(string $name): Issue
    {
        return Issue::create([
            'user_id'         => $this->author->id,
            'organization_id' => $this->org->id,
            'team_id'         => $this->team->id,
            'name'            => $name,
            'description'     => 'desc',
            'type'            => 'development',
            'status'          => 'open',
        ]);
    }

    private function mockLlmGroups(array $groups): void
    {
        $mock = Mockery::mock(OpenRouterClient::class);
        $mock->shouldReceive('chat')->andReturn(json_encode(['groups' => $groups]));
        $this->app->instance(OpenRouterClient::class, $mock);
    }

    #[Test]
    public function it_persists_a_group_with_one_field(): void
    {
        $newTask = $this->makeTask('Move CI to GHA new');
        $existingTask = $this->makeTask('Move CI to GHA existing');

        $this->mockLlmGroups([[
            'members' => [
                ['issue_id' => $newTask->id, 'role' => 'new'],
                ['issue_id' => $existingTask->id, 'role' => 'existing'],
            ],
            'fields'  => ['due_date'],
            'summary' => 'Разные дедлайны на одно и то же.',
        ]]);

        $detector = $this->app->make(IssueConflictDetector::class);
        $result = $detector->detect([$newTask->id], $this->event);

        $this->assertCount(1, $result);
        $this->assertDatabaseCount('issue_conflicts', 2); // 2 issues × 1 field
        $this->assertDatabaseHas('issue_conflicts', [
            'issue_id'                      => $newTask->id,
            'field'                         => 'due_date',
            'status'                        => 'open',
            'detected_in_calendar_event_id' => $this->event->id,
        ]);
        $this->assertDatabaseHas('issue_conflicts', [
            'issue_id' => $existingTask->id,
            'field'    => 'due_date',
        ]);
    }

    #[Test]
    public function it_creates_multiple_rows_for_multi_field_group(): void
    {
        $newTask = $this->makeTask('Plan A');
        $existingTask = $this->makeTask('Plan B');

        $this->mockLlmGroups([[
            'members' => [
                ['issue_id' => $newTask->id, 'role' => 'new'],
                ['issue_id' => $existingTask->id, 'role' => 'existing'],
            ],
            'fields'  => ['requirements', 'due_date'],
            'summary' => 'Разные сроки и противоречивые требования.',
        ]]);

        $detector = $this->app->make(IssueConflictDetector::class);
        $detector->detect([$newTask->id], $this->event);

        $this->assertDatabaseCount('issue_conflicts', 4); // 2 × 2
    }

    #[Test]
    public function it_skips_group_with_fewer_than_two_members(): void
    {
        $newTask = $this->makeTask('Alone');
        $this->makeTask('Other'); // exists but not in LLM group

        $this->mockLlmGroups([[
            'members' => [['issue_id' => $newTask->id, 'role' => 'new']],
            'fields'  => ['due_date'],
            'summary' => 'Не должно сохраниться.',
        ]]);

        $detector = $this->app->make(IssueConflictDetector::class);
        $detector->detect([$newTask->id], $this->event);

        $this->assertDatabaseCount('issue_conflicts', 0);
    }

    #[Test]
    public function it_skips_group_with_invalid_field(): void
    {
        $newTask = $this->makeTask('X');
        $existingTask = $this->makeTask('Y');

        $this->mockLlmGroups([[
            'members' => [
                ['issue_id' => $newTask->id, 'role' => 'new'],
                ['issue_id' => $existingTask->id, 'role' => 'existing'],
            ],
            'fields'  => ['something_else'], // not in IssueConflict::FIELDS
            'summary' => 'Should be filtered out.',
        ]]);

        $detector = $this->app->make(IssueConflictDetector::class);
        $detector->detect([$newTask->id], $this->event);

        $this->assertDatabaseCount('issue_conflicts', 0);
    }

    #[Test]
    public function it_is_idempotent_on_retry_for_same_event(): void
    {
        $newTask = $this->makeTask('A');
        $existingTask = $this->makeTask('B');

        $this->mockLlmGroups([[
            'members' => [
                ['issue_id' => $newTask->id, 'role' => 'new'],
                ['issue_id' => $existingTask->id, 'role' => 'existing'],
            ],
            'fields'  => ['due_date'],
            'summary' => 'Same conflict.',
        ]]);

        $detector = $this->app->make(IssueConflictDetector::class);
        $detector->detect([$newTask->id], $this->event);
        $detector->detect([$newTask->id], $this->event);

        $this->assertDatabaseCount('issue_conflicts', 2); // not duplicated
    }

    #[Test]
    public function it_preserves_resolved_conflicts_on_replay(): void
    {
        $newTask = $this->makeTask('A');
        $existingTask = $this->makeTask('B');

        // Pre-create a resolved conflict for the same event
        IssueConflict::create([
            'conflict_group_uuid'           => \Illuminate\Support\Str::orderedUuid(),
            'issue_id'                      => $newTask->id,
            'field'                         => 'due_date',
            'conflict_summary'              => 'old, resolved by user',
            'detected_in_calendar_event_id' => $this->event->id,
            'status'                        => IssueConflict::STATUS_RESOLVED,
        ]);

        $this->mockLlmGroups([[
            'members' => [
                ['issue_id' => $newTask->id, 'role' => 'new'],
                ['issue_id' => $existingTask->id, 'role' => 'existing'],
            ],
            'fields'  => ['assignee'],
            'summary' => 'fresh conflict',
        ]]);

        $detector = $this->app->make(IssueConflictDetector::class);
        $detector->detect([$newTask->id], $this->event);

        $this->assertDatabaseHas('issue_conflicts', [
            'issue_id' => $newTask->id,
            'status'   => IssueConflict::STATUS_RESOLVED,
        ]);
        $this->assertDatabaseHas('issue_conflicts', [
            'issue_id' => $newTask->id,
            'field'    => 'assignee',
            'status'   => 'open',
        ]);
    }

    #[Test]
    public function it_handles_empty_existing_pool_gracefully(): void
    {
        $newTask = $this->makeTask('Only one task in team');

        // No mock — should never call LLM because pool is empty

        $detector = $this->app->make(IssueConflictDetector::class);
        $result = $detector->detect([$newTask->id], $this->event);

        $this->assertSame([], $result);
        $this->assertDatabaseCount('issue_conflicts', 0);
    }

    #[Test]
    public function it_propagates_llm_failure_to_caller_for_retry(): void
    {
        $newTask = $this->makeTask('A');
        $this->makeTask('B');

        $mock = Mockery::mock(OpenRouterClient::class);
        $mock->shouldReceive('chat')->andThrow(new \RuntimeException('LLM down'));
        $this->app->instance(OpenRouterClient::class, $mock);

        $detector = $this->app->make(IssueConflictDetector::class);

        // Detector must NOT swallow the LLM exception — DetectIssueConflictsJob's retry
        // depends on it bubbling up (CLAUDE.md rule 3).
        $this->expectException(\RuntimeException::class);
        $detector->detect([$newTask->id], $this->event);
    }

    #[Test]
    public function it_writes_conflicts_snapshot_to_meeting_summary(): void
    {
        $summary = MeetingSummary::create([
            'calendar_event_id' => $this->event->id,
            'status'            => 'ready',
            'title'             => 'Q1',
            'summary'           => 'x',
        ]);

        $newTask = $this->makeTask('A');
        $existingTask = $this->makeTask('B');

        $this->mockLlmGroups([[
            'members' => [
                ['issue_id' => $newTask->id, 'role' => 'new'],
                ['issue_id' => $existingTask->id, 'role' => 'existing'],
            ],
            'fields'  => ['due_date'],
            'summary' => 'A vs B due dates differ.',
        ]]);

        $detector = $this->app->make(IssueConflictDetector::class);
        $detector->detect([$newTask->id], $this->event);

        $summary->refresh();
        $conflicts = $summary->conflicts;
        $this->assertIsArray($conflicts);
        $this->assertCount(1, $conflicts);
        $this->assertSame('A vs B due dates differ.', $conflicts[0]['summary']);
    }

    #[Test]
    public function it_skips_done_issues_from_pool(): void
    {
        $newTask = $this->makeTask('Active');

        $done = $this->makeTask('Done one');
        $done->update(['status' => 'done']);

        // LLM should be asked but won't see the "done" issue. We simulate "no conflict".
        $this->mockLlmGroups([]);

        $detector = $this->app->make(IssueConflictDetector::class);
        $detector->detect([$newTask->id], $this->event);

        $this->assertDatabaseCount('issue_conflicts', 0);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
