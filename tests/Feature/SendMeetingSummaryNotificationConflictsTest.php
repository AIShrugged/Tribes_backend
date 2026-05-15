<?php

namespace Tests\Feature;

use App\Listeners\SendMeetingSummaryNotification;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\MeetingSummary;
use App\Models\Organization;
use App\Models\Source;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class SendMeetingSummaryNotificationConflictsTest extends TestCase
{
    use RefreshDatabase;

    private function makeSummaryWithConflicts(array $conflicts): MeetingSummary
    {
        $org = Organization::create(['name' => 'R Org', 'slug' => 'r-org']);
        $author = User::factory()->create();
        $team = Team::create(['name' => 'R Team', 'slug' => 'r-team', 'organization_id' => $org->id]);
        $source = Source::create([
            'user_id' => $author->id, 'type' => 'google_calendar',
            'external_id' => 'r-src', 'identity' => 'r@a.com',
        ]);
        $event = CalendarEvent::create([
            'source_id' => $source->id, 'external_id' => 'r-event',
            'platform' => 'google_meet', 'title' => 'R',
            'description' => '', 'url' => 'https://x', 'starts_at' => now(),
            'ends_at' => now()->addHour(), 'required_bot' => false,
        ]);

        return MeetingSummary::create([
            'calendar_event_id' => $event->id,
            'status'            => 'ready',
            'title'             => 'R',
            'summary'           => 'x',
            'conflicts'         => $conflicts,
        ]);
    }

    private function callRenderConflicts(MeetingSummary $summary): ?string
    {
        $listener = new SendMeetingSummaryNotification();
        $method = new ReflectionMethod($listener, 'renderConflicts');
        $method->setAccessible(true);

        return $method->invoke($listener, $summary);
    }

    #[Test]
    public function it_renders_conflicts_section_when_present(): void
    {
        $org = Organization::create(['name' => 'C Org', 'slug' => 'c-org']);
        $author = User::factory()->create();
        $issueA = Issue::create([
            'user_id' => $author->id, 'organization_id' => $org->id,
            'name' => 'Migrate CI to GHA', 'type' => 'development', 'status' => 'open',
        ]);
        $issueB = Issue::create([
            'user_id' => $author->id, 'organization_id' => $org->id,
            'name' => 'CI move (old)', 'type' => 'development', 'status' => 'open',
        ]);

        $summary = $this->makeSummaryWithConflicts([
            [
                'group_uuid' => 'uuid-1',
                'members'    => [
                    ['issue_id' => $issueA->id],
                    ['issue_id' => $issueB->id],
                ],
                'fields'     => ['due_date'],
                'summary'    => 'Разные сроки на одно и то же.',
            ],
        ]);

        $rendered = $this->callRenderConflicts($summary);

        $this->assertNotNull($rendered);
        $this->assertStringContainsString('Найденные конфликты', $rendered);
        $this->assertStringContainsString('Migrate CI to GHA', $rendered);
        $this->assertStringContainsString('CI move (old)', $rendered);
        $this->assertStringContainsString('дедлайн', $rendered);
        $this->assertStringContainsString('Разные сроки', $rendered);
    }

    #[Test]
    public function it_omits_conflicts_section_when_empty(): void
    {
        $summary = $this->makeSummaryWithConflicts([]);

        $rendered = $this->callRenderConflicts($summary);

        $this->assertNull($rendered);
    }

    #[Test]
    public function it_omits_conflicts_section_when_null(): void
    {
        $org = Organization::create(['name' => 'N Org', 'slug' => 'n-org']);
        $author = User::factory()->create();
        $source = Source::create([
            'user_id' => $author->id, 'type' => 'google_calendar',
            'external_id' => 'n-src', 'identity' => 'n@a.com',
        ]);
        $event = CalendarEvent::create([
            'source_id' => $source->id, 'external_id' => 'n-event',
            'platform' => 'google_meet', 'title' => 'N',
            'description' => '', 'url' => 'https://x', 'starts_at' => now(),
            'ends_at' => now()->addHour(), 'required_bot' => false,
        ]);
        $summary = MeetingSummary::create([
            'calendar_event_id' => $event->id,
            'status'            => 'ready',
            'title'             => 'N',
            'summary'           => 'x',
        ]);

        $rendered = $this->callRenderConflicts($summary);
        $this->assertNull($rendered);
    }
}
