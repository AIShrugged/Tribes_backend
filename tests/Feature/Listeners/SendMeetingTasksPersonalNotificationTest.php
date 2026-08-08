<?php

namespace Tests\Feature\Listeners;

use App\Events\MeetingTasksExtracted;
use App\Listeners\SendMeetingTasksPersonalNotification;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Source;
use App\Models\Team;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SendMeetingTasksPersonalNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $author;
    private CalendarEvent $calendarEvent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create();

        $source = Source::create([
            'user_id'     => $this->author->id,
            'type'        => 'google_calendar',
            'external_id' => 'src-personal-notif-test',
            'identity'    => 'author@example.com',
        ]);

        $this->calendarEvent = CalendarEvent::create([
            'source_id'   => $source->id,
            'external_id' => 'evt-personal-notif-test',
            'platform'    => 'google_meet',
            'title'       => 'Q2 Planning',
            'url'         => 'https://meet.google.com/q2-planning',
            'starts_at'   => now(),
            'ends_at'     => now()->addHour(),
        ]);
    }

    #[Test]
    public function it_sends_personal_telegram_message_to_author_when_telegram_user_exists(): void
    {
        TelegramUser::create([
            'telegram_user_id' => 123456789,
            'user_id'          => $this->author->id,
        ]);

        $issues = new Collection([
            $this->makeIssue('Fix login bug', 'Alice'),
            $this->makeIssue('Write unit tests', null),
        ]);

        $telegramApi = Mockery::mock('overload:Telegram\Bot\Api');
        $telegramApi->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (array $params) use ($issues): bool {
                if ($params['chat_id'] !== 123456789) {
                    return false;
                }

                $text = $params['text'];

                foreach ($issues as $issue) {
                    if (! str_contains($text, $issue->name)) {
                        return false;
                    }
                }

                return true;
            });

        $listener = new SendMeetingTasksPersonalNotification();
        $listener->handle(new MeetingTasksExtracted($this->calendarEvent, $issues));
    }

    #[Test]
    public function it_does_not_send_telegram_message_when_author_has_no_telegram_user(): void
    {
        // No TelegramUser created for $this->author intentionally

        $telegramApi = Mockery::mock('overload:Telegram\Bot\Api');
        $telegramApi->shouldNotReceive('sendMessage');

        $issues = new Collection([
            $this->makeIssue('Deploy to staging', null),
        ]);

        $listener = new SendMeetingTasksPersonalNotification();

        // Must not throw
        $listener->handle(new MeetingTasksExtracted($this->calendarEvent, $issues));
    }

    private function makeIssue(string $name, ?string $assigneeName): Issue
    {
        $methodology = Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create([
                'name'       => 'Default',
                'text'       => 'Default methodology text',
                'scheme'     => '{}',
                'is_default' => true,
            ]);

        $organization = Organization::create([
            'name' => 'Org ' . uniqid(),
            'slug' => 'org-' . uniqid(),
        ]);

        $team = Team::create([
            'organization_id' => $organization->id,
            'methodology_id'  => $methodology->id,
            'name'            => 'Team ' . uniqid(),
            'slug'            => 'team-' . uniqid(),
        ]);

        return Issue::create([
            'user_id'         => $this->author->id,
            'organization_id' => $organization->id,
            'team_id'         => $team->id,
            'name'            => $name,
            'assignee_name'   => $assigneeName,
            'type'            => 'organization',
            'status'          => 'open',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
