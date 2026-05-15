<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Source;
use App\Models\Team;
use App\Models\User;
use App\Services\IssueMergeService;
use App\Services\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IssueMergeServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Team $team;
    private User $user;
    private CalendarEvent $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Merge Org', 'slug' => 'merge-org']);

        $this->user = User::factory()->create();
        $this->org->users()->attach($this->user, ['role' => 'employee']);

        $methodology = Methodology::create([
            'name'            => 'Test',
            'text'            => 'Test methodology.',
            'scheme'          => json_encode(['type' => 'object']),
            'organization_id' => $this->org->id,
        ]);

        $this->team = Team::create([
            'name'            => 'Merge Team',
            'slug'            => 'merge-team',
            'organization_id' => $this->org->id,
            'methodology_id'  => $methodology->id,
        ]);
        $this->team->users()->attach($this->user);

        $source = Source::create([
            'user_id'     => $this->user->id,
            'type'        => 'google_calendar',
            'external_id' => 'merge-src',
            'identity'    => 'user@example.com',
        ]);

        $this->event = CalendarEvent::create([
            'source_id'    => $source->id,
            'external_id'  => 'merge-event',
            'platform'     => 'google_meet',
            'title'        => 'Sprint Review',
            'description'  => '',
            'url'          => 'https://meet.google.com/x',
            'starts_at'    => now(),
            'ends_at'      => now()->addHour(),
            'required_bot' => false,
        ]);
    }

    private function mockLlmDecisions(array $decisions): void
    {
        $mock = Mockery::mock(OpenRouterClient::class);
        $mock->shouldReceive('chat')->andReturn(json_encode(['decisions' => $decisions]));
        $this->app->instance(OpenRouterClient::class, $mock);
    }

    // ── 1. No existing issues → createAll path (no LLM call) ──

    #[Test]
    public function persist_creates_all_issues_when_none_exist(): void
    {
        $service = $this->app->make(IssueMergeService::class);

        $items = [
            ['name' => 'Fix login bug', 'description' => 'OAuth session lost', 'type' => 'backend'],
            ['name' => 'Export PDF',    'description' => 'Clients need PDF export', 'type' => 'organization'],
        ];

        $issues = $service->persist($items, $this->event, $this->team, $this->user);

        $this->assertCount(2, $issues);
        $this->assertDatabaseCount('issues', 2);
        $this->assertDatabaseHas('issues', ['name' => 'Fix login bug']);
        $this->assertDatabaseHas('issues', ['name' => 'Export PDF']);
    }

    // ── 2. LLM says "create" → new issue inserted ──

    #[Test]
    public function apply_decisions_create_action_inserts_new_issue(): void
    {
        Issue::create([
            'user_id'         => $this->user->id,
            'organization_id' => $this->org->id,
            'team_id'         => $this->team->id,
            'name'            => 'Old task',
            'type'            => 'organization',
            'status'          => 'open',
        ]);

        $this->mockLlmDecisions([
            ['index' => 0, 'action' => 'create'],
        ]);

        $service = $this->app->make(IssueMergeService::class);
        $issues = $service->persist(
            [['name' => 'New task', 'description' => '', 'type' => 'organization']],
            $this->event, $this->team, $this->user
        );

        $this->assertCount(1, $issues);
        $this->assertDatabaseHas('issues', ['name' => 'New task']);
        $this->assertDatabaseCount('issues', 2);
    }

    // ── 3. LLM says "update" → comment added, existing issue updated ──

    #[Test]
    public function apply_decisions_update_action_adds_comment(): void
    {
        $existing = Issue::create([
            'user_id'         => $this->user->id,
            'organization_id' => $this->org->id,
            'team_id'         => $this->team->id,
            'name'            => 'Existing issue',
            'type'            => 'backend',
            'status'          => 'open',
        ]);

        $this->mockLlmDecisions([
            [
                'index'              => 0,
                'action'             => 'update',
                'existing_issue_id'  => $existing->id,
                'update_description' => 'More context from sprint review',
            ],
        ]);

        $service = $this->app->make(IssueMergeService::class);
        $service->persist(
            [['name' => 'Same issue different wording', 'description' => '', 'type' => 'backend']],
            $this->event, $this->team, $this->user
        );

        $this->assertDatabaseCount('issues', 1);
        $this->assertDatabaseCount('issue_comments', 1);
        $this->assertDatabaseHas('issue_comments', [
            'issue_id' => $existing->id,
        ]);
    }

    // ── 4. LLM says "skip" → no new issue created ──

    #[Test]
    public function apply_decisions_skip_action_creates_no_issue(): void
    {
        $existing = Issue::create([
            'user_id'         => $this->user->id,
            'organization_id' => $this->org->id,
            'team_id'         => $this->team->id,
            'name'            => 'Already tracked',
            'type'            => 'organization',
            'status'          => 'open',
        ]);

        $this->mockLlmDecisions([
            ['index' => 0, 'action' => 'skip'],
        ]);

        $service = $this->app->make(IssueMergeService::class);
        $result = $service->persist(
            [['name' => 'Duplicate task', 'description' => '', 'type' => 'organization']],
            $this->event, $this->team, $this->user
        );

        $this->assertCount(0, $result);
        $this->assertDatabaseCount('issues', 1);
    }

    // ── 5. LLM omits an index → that item is created anyway ──

    #[Test]
    public function items_omitted_by_llm_are_created_to_prevent_data_loss(): void
    {
        Issue::create([
            'user_id'         => $this->user->id,
            'organization_id' => $this->org->id,
            'team_id'         => $this->team->id,
            'name'            => 'Old task',
            'type'            => 'organization',
            'status'          => 'open',
        ]);

        // LLM only covers index 0 but not index 1 — index 1 must be created anyway
        $this->mockLlmDecisions([
            ['index' => 0, 'action' => 'create'],
        ]);

        $service = $this->app->make(IssueMergeService::class);
        $result = $service->persist(
            [
                ['name' => 'Task A', 'description' => '', 'type' => 'organization'],
                ['name' => 'Task B', 'description' => '', 'type' => 'organization'],
            ],
            $this->event, $this->team, $this->user
        );

        $this->assertCount(2, $result);
        $this->assertDatabaseHas('issues', ['name' => 'Task A']);
        $this->assertDatabaseHas('issues', ['name' => 'Task B']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
