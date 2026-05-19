<?php

namespace Tests\Feature;

use App\Enums\AgendaStatus;
use App\Models\CalendarEvent;
use App\Models\MeetingAgenda;
use App\Models\Profile;
use App\Models\Source;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SendMorningBriefCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_includes_meeting_goal_from_general_agenda(): void
    {
        $user = User::factory()->create();
        TelegramUser::create([
            'user_id' => $user->id,
            'telegram_user_id' => '12345',
        ]);

        $source = Source::create([
            'user_id' => $user->id,
            'type' => 'google_calendar',
            'external_id' => 'src1',
            'identity' => 'owner@example.com',
        ]);

        $event = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'event-1',
            'platform' => 'google_meet',
            'url' => 'https://meet.google.com/abc-def',
            'description' => '',
            'title' => 'Sprint Planning',
            'starts_at' => now()->startOfDay()->addHours(10),
            'ends_at' => now()->startOfDay()->addHours(11),
            'required_bot' => false,
        ]);

        MeetingAgenda::create([
            'calendar_event_id' => $event->id,
            'type' => 'general',
            'status' => AgendaStatus::DONE->value,
            'user_id' => null,
            'raw_json' => ['meeting_goal' => 'Распланировать спринт 42'],
        ]);

        // Use --test-user to avoid touching real Telegram API; just exercise the formatting path.
        $this->artisan('meetings:send-morning-brief', ['--test-user' => '99999'])
            ->assertExitCode(0);

        // Verify the relation works (eager-loaded constraint)
        $event->refresh();
        $event->load('generalAgenda');
        $this->assertNotNull($event->generalAgenda);
        $this->assertSame('Распланировать спринт 42', $event->generalAgenda->raw_json['meeting_goal']);
    }

    #[Test]
    public function general_agenda_relation_excludes_personal_and_incomplete(): void
    {
        $user = User::factory()->create();
        $source = Source::create([
            'user_id' => $user->id,
            'type' => 'google_calendar',
            'external_id' => 'src1',
            'identity' => 'owner@example.com',
        ]);

        $event = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'event-1',
            'platform' => 'google_meet',
            'url' => 'https://meet.google.com/abc-def',
            'description' => '',
            'title' => 'Meeting',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
            'required_bot' => false,
        ]);

        // Personal agenda (user_id != null) — should be excluded
        $profile = Profile::create([
            'user_id' => $user->id,
            'channel_id' => 1,
            'channel_identifier' => 'p1',
            'name' => 'P',
        ]);
        MeetingAgenda::create([
            'calendar_event_id' => $event->id,
            'type' => 'personal',
            'status' => AgendaStatus::DONE->value,
            'user_id' => $user->id,
            'raw_json' => ['meeting_goal' => 'Personal goal — should NOT appear'],
        ]);

        // In-progress general agenda — should be excluded
        MeetingAgenda::create([
            'calendar_event_id' => $event->id,
            'type' => 'general',
            'status' => 'in_progress',
            'user_id' => null,
            'raw_json' => ['meeting_goal' => 'In progress — should NOT appear'],
        ]);

        $event->refresh();
        $event->load('generalAgenda');

        $this->assertNull($event->generalAgenda, 'No completed general agenda exists, relation should be null');
    }

    #[Test]
    public function general_agenda_returns_latest_when_multiple_done_exist(): void
    {
        $user = User::factory()->create();
        $source = Source::create([
            'user_id' => $user->id,
            'type' => 'google_calendar',
            'external_id' => 'src1',
            'identity' => 'owner@example.com',
        ]);

        $event = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'event-1',
            'platform' => 'google_meet',
            'url' => 'https://meet.google.com/abc-def',
            'description' => '',
            'title' => 'Meeting',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
            'required_bot' => false,
        ]);

        $older = MeetingAgenda::create([
            'calendar_event_id' => $event->id,
            'type' => 'general',
            'status' => AgendaStatus::DONE->value,
            'user_id' => null,
            'raw_json' => ['meeting_goal' => 'Old goal'],
        ]);
        $newer = MeetingAgenda::create([
            'calendar_event_id' => $event->id,
            'type' => 'general',
            'status' => AgendaStatus::DONE->value,
            'user_id' => null,
            'raw_json' => ['meeting_goal' => 'Newer goal'],
        ]);

        $event->refresh();
        $event->load('generalAgenda');

        $this->assertSame($newer->id, $event->generalAgenda->id);
        $this->assertSame('Newer goal', $event->generalAgenda->raw_json['meeting_goal']);
    }
}
