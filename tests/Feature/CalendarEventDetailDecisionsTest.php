<?php

namespace Tests\Feature;

use App\Enums\DecisionSourceType;
use App\Models\CalendarEvent;
use App\Models\Decision;
use App\Models\Issue;
use App\Models\MeetingSummary;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Source;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CalendarEventDetailDecisionsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Team $team;
    private User $owner;
    private CalendarEvent $event;
    private MeetingSummary $summary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'D Org', 'slug' => 'd-org']);
        $this->owner = User::factory()->create();
        $this->org->users()->attach($this->owner, ['role' => 'employee']);

        $methodology = Methodology::create([
            'name' => 'M', 'text' => 'M', 'scheme' => json_encode(['type' => 'object']),
            'organization_id' => $this->org->id,
        ]);

        $this->team = Team::create([
            'name' => 'D Team', 'slug' => 'd-team',
            'organization_id' => $this->org->id,
            'methodology_id'  => $methodology->id,
        ]);
        $this->team->users()->attach($this->owner);

        $source = Source::create([
            'user_id'     => $this->owner->id,
            'type'        => 'google_calendar',
            'external_id' => 'd-src',
            'identity'    => 'owner@example.com',
        ]);

        $this->event = CalendarEvent::create([
            'source_id'    => $source->id,
            'external_id'  => 'd-event',
            'platform'     => 'google_meet',
            'title'        => 'Decisions Dashboard Meeting',
            'description'  => '',
            'url'          => 'https://meet.google.com/x',
            'starts_at'    => now(),
            'ends_at'      => now()->addHour(),
            'required_bot' => false,
        ]);

        // Привязываем встречу к pivot calendar_event_source, чтобы Auth-пользователь видел её.
        $this->event->sources()->attach($source->id);

        $this->summary = MeetingSummary::create([
            'calendar_event_id' => $this->event->id,
            'summary'           => 'Test summary',
            'key_points'        => ['kp1'],
            'decisions'         => ['legacy decision text'],
        ]);
    }

    #[Test]
    public function dashboard_returns_decisions_with_linked_issues_and_uncovered_flag(): void
    {
        // 1 решение покрыто 1 задачей, 2-е решение — без задач (uncovered).
        $coveredIssue = Issue::create([
            'user_id'         => $this->owner->id,
            'organization_id' => $this->org->id,
            'team_id'         => $this->team->id,
            'name'            => 'Implement feature X',
            'type'            => 'backend',
            'status'          => 'open',
        ]);

        $covered = Decision::create([
            'calendar_event_id' => $this->event->id,
            'summary_id'        => $this->summary->id,
            'team_id'           => $this->team->id,
            'organization_id'   => $this->org->id,
            'source_type'       => DecisionSourceType::Meeting->value,
            'author_raw_name'   => 'Alice',
            'text'              => 'We will ship feature X by Friday',
            'topic'              => 'feature-x',
        ]);

        DB::table('decision_issue')->insert([
            'decision_id' => $covered->id,
            'issue_id'    => $coveredIssue->id,
            'created_at'  => now(),
        ]);

        $uncovered = Decision::create([
            'calendar_event_id' => $this->event->id,
            'summary_id'        => $this->summary->id,
            'team_id'           => $this->team->id,
            'organization_id'   => $this->org->id,
            'source_type'       => DecisionSourceType::Meeting->value,
            'author_raw_name'   => 'Bob',
            'text'              => 'We should rethink the onboarding flow',
            'topic'              => 'onboarding',
        ]);

        Sanctum::actingAs($this->owner);

        $response = $this->getJson("/api/v1/calendar-events/{$this->event->id}/detail");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'decisions' => [
                    '*' => ['id', 'text', 'topic', 'author_raw_name', 'linked_issues', 'is_uncovered'],
                ],
            ],
        ]);

        $decisions = $response->json('data.decisions');
        $this->assertCount(2, $decisions);

        $coveredOut = collect($decisions)->firstWhere('id', $covered->id);
        $this->assertNotNull($coveredOut);
        $this->assertFalse($coveredOut['is_uncovered']);
        $this->assertCount(1, $coveredOut['linked_issues']);
        $this->assertSame($coveredIssue->id, $coveredOut['linked_issues'][0]['id']);
        $this->assertSame('Implement feature X', $coveredOut['linked_issues'][0]['name']);

        $uncoveredOut = collect($decisions)->firstWhere('id', $uncovered->id);
        $this->assertNotNull($uncoveredOut);
        $this->assertTrue($uncoveredOut['is_uncovered']);
        $this->assertEmpty($uncoveredOut['linked_issues']);
    }

    #[Test]
    public function dashboard_returns_empty_decisions_when_meeting_has_none(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->getJson("/api/v1/calendar-events/{$this->event->id}/detail");

        $response->assertOk();
        $this->assertSame([], $response->json('data.decisions'));
    }
}
