<?php

namespace Tests\Feature;

use App\Listeners\SendMeetingSummaryNotification;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Organization;
use App\Models\Source;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression guard for the "Обновлённые задачи и цели" list in the meeting Telegram
 * summary. The old query filtered updated issues by an IssueComment with user_id NULL,
 * but merge comments always carry a non-null author (US-6.8), so the list was always
 * empty in production. The fix keys updates off calendar_event_id only.
 */
class SendMeetingSummaryNotificationTasksTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'T Org', 'slug' => 't-org']);
        $this->author = User::factory()->create();
    }

    private function makeEvent(string $key): CalendarEvent
    {
        $source = Source::create([
            'user_id' => $this->author->id, 'type' => 'google_calendar',
            'external_id' => "src-{$key}", 'identity' => "{$key}@a.com",
        ]);

        return CalendarEvent::create([
            'source_id' => $source->id, 'external_id' => "event-{$key}",
            'platform' => 'google_meet', 'title' => "Meeting {$key}",
            'description' => '', 'url' => "https://x/{$key}", 'starts_at' => now(),
            'ends_at' => now()->addHour(), 'required_bot' => false,
        ]);
    }

    private function makeIssue(array $attrs = []): Issue
    {
        return Issue::create(array_merge([
            'user_id' => $this->author->id,
            'organization_id' => $this->org->id,
            'name' => 'Some task',
            'type' => 'development',
            'status' => 'open',
        ], $attrs));
    }

    /**
     * @return array{0: Collection<int, Issue>, 1: Collection<int, Issue>}
     */
    private function resolveTasks(CalendarEvent $event): array
    {
        $method = new ReflectionMethod(SendMeetingSummaryNotification::class, 'resolveMeetingTasks');
        $method->setAccessible(true);

        return $method->invoke(new SendMeetingSummaryNotification(), $event);
    }

    #[Test]
    public function it_lists_a_pre_existing_issue_updated_by_this_meeting_as_updated(): void
    {
        $prev = $this->makeEvent('prev');
        $event = $this->makeEvent('now');

        // Pre-existing issue, created from a PREVIOUS meeting.
        $issue = $this->makeIssue([
            'name' => 'Carryover task',
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $prev->id,
        ]);

        // This meeting touched it via a merge comment — author is NON-null (the bug regression).
        IssueComment::create([
            'issue_id' => $issue->id,
            'user_id' => $this->author->id,
            'parent_id' => null,
            'calendar_event_id' => $event->id,
            'content' => "**Обновление по встрече \"Meeting now\":**\n\nДедлайн сдвинут.",
        ]);

        [$new, $updated] = $this->resolveTasks($event);

        $this->assertTrue($new->isEmpty(), 'Carryover issue must NOT be counted as new.');
        $this->assertEqualsCanonicalizing([$issue->id], $updated->pluck('id')->all());
    }

    #[Test]
    public function it_lists_an_issue_sourced_from_this_meeting_as_new_only(): void
    {
        $event = $this->makeEvent('now');

        $issue = $this->makeIssue([
            'name' => 'Fresh task',
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $event->id,
        ]);

        [$new, $updated] = $this->resolveTasks($event);

        $this->assertEqualsCanonicalizing([$issue->id], $new->pluck('id')->all());
        $this->assertTrue($updated->isEmpty(), 'A freshly-sourced issue must not appear in updated.');
    }

    #[Test]
    public function it_ignores_plain_user_comments_and_other_meetings(): void
    {
        $event = $this->makeEvent('now');
        $other = $this->makeEvent('other');

        // Pre-existing issue with a plain user comment (calendar_event_id NULL — as written by the API).
        $userCommented = $this->makeIssue(['name' => 'User-commented task', 'sourceable_type' => null, 'sourceable_id' => null]);
        IssueComment::create([
            'issue_id' => $userCommented->id,
            'user_id' => $this->author->id,
            'parent_id' => null,
            'calendar_event_id' => null,
            'content' => 'Just a human note.',
        ]);

        // Pre-existing issue updated by a DIFFERENT meeting.
        $otherMeeting = $this->makeIssue(['name' => 'Other-meeting task']);
        IssueComment::create([
            'issue_id' => $otherMeeting->id,
            'user_id' => $this->author->id,
            'parent_id' => null,
            'calendar_event_id' => $other->id,
            'content' => '**Обновление по встрече "Meeting other":**',
        ]);

        [$new, $updated] = $this->resolveTasks($event);

        $this->assertTrue($new->isEmpty());
        $this->assertTrue($updated->isEmpty(), 'User comments and other meetings must not leak into updated.');
    }

    #[Test]
    public function it_excludes_cancelled_issues_from_updated(): void
    {
        $event = $this->makeEvent('now');

        $cancelled = $this->makeIssue([
            'name' => 'Cancelled carryover',
            'status' => 'cancelled',
            'sourceable_type' => null,
            'sourceable_id' => null,
        ]);
        IssueComment::create([
            'issue_id' => $cancelled->id,
            'user_id' => $this->author->id,
            'parent_id' => null,
            'calendar_event_id' => $event->id,
            'content' => '**Обновление по встрече:** уже неактуально.',
        ]);

        [$new, $updated] = $this->resolveTasks($event);

        $this->assertTrue($new->isEmpty());
        $this->assertTrue($updated->isEmpty(), 'Cancelled issues must not appear in the updated list.');
    }

    #[Test]
    public function it_renders_both_new_and_updated_sections(): void
    {
        $newTasks = new Collection([$this->makeIssue(['name' => 'New one'])]);
        $updatedTasks = new Collection([$this->makeIssue(['name' => 'Updated one', 'status' => 'in_progress'])]);

        $method = new ReflectionMethod(SendMeetingSummaryNotification::class, 'renderTasks');
        $method->setAccessible(true);
        $rendered = $method->invoke(new SendMeetingSummaryNotification(), $newTasks, $updatedTasks);

        $this->assertNotNull($rendered);
        $this->assertStringContainsString('Новые задачи и цели', $rendered);
        $this->assertStringContainsString('New one', $rendered);
        $this->assertStringContainsString('Обновлённые задачи и цели', $rendered);
        $this->assertStringContainsString('Updated one', $rendered);
    }
}
