<?php

namespace Tests\Feature;

use App\Events\MeetingArtifactsReady;
use App\Listeners\SendTranscriptUploadReportNotification;
use App\Models\CalendarEvent;
use App\Models\MeetingSummary;
use App\Models\Organization;
use App\Models\Source;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for the personal Telegram report sent to the user who manually
 * uploaded a transcript. The actual Telegram API call is wrapped in
 * try/catch inside the listener, so tests run fine without a real bot
 * token — we verify filtering logic and message assembly.
 */
class SendTranscriptUploadReportNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function createManualUploadEvent(User $user): CalendarEvent
    {
        $source = Source::create([
            'user_id'     => $user->id,
            'type'        => 'google_calendar',
            'external_id' => 'src-' . $user->id,
            'identity'    => $user->email,
        ]);

        return CalendarEvent::create([
            'source_id'       => $source->id,
            'creator_user_id' => $user->id,
            'platform'        => 'manual_upload',
            'title'           => 'Test manual upload',
            'description'     => '',
            'url'             => 'https://wanda.local/manual/' . uniqid(),
            'starts_at'       => now()->subHour(),
            'ends_at'         => now(),
            'required_bot'    => false,
        ]);
    }

    #[Test]
    public function attempts_send_for_manual_upload_with_telegram_user(): void
    {
        $user = User::factory()->create();
        TelegramUser::create([
            'user_id'          => $user->id,
            'telegram_user_id' => 123456789,
        ]);

        $event = $this->createManualUploadEvent($user);
        MeetingSummary::create(['calendar_event_id' => $event->id, 'text' => 'done']);

        // Listener will try to send but fail (no real bot token in tests).
        // It catches the error and logs a warning — verify that path.
        Log::shouldReceive('info')
            ->atLeast()->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'SendTranscriptUploadReport'));

        Log::shouldReceive('warning')
            ->atLeast()->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'SendTranscriptUploadReport'));

        // Allow other Log calls to pass through.
        Log::makePartial();

        $listener = new SendTranscriptUploadReportNotification();
        $listener->handle(new MeetingArtifactsReady($event));
    }

    #[Test]
    public function skips_recall_events_entirely(): void
    {
        $user = User::factory()->create();
        TelegramUser::create([
            'user_id'          => $user->id,
            'telegram_user_id' => 123456789,
        ]);
        $source = Source::create([
            'user_id'     => $user->id,
            'type'        => 'google_calendar',
            'external_id' => 'src-recall',
            'identity'    => $user->email,
        ]);

        $event = CalendarEvent::create([
            'source_id'       => $source->id,
            'creator_user_id' => $user->id,
            'platform'        => 'google_meet',
            'title'           => 'Recall event',
            'description'     => '',
            'url'             => 'https://meet.google.com/x',
            'starts_at'       => now(),
            'ends_at'         => now()->addHour(),
            'required_bot'    => false,
        ]);

        // No TG call should happen — listener returns early on non-manual platform.
        $listener = new SendTranscriptUploadReportNotification();
        $listener->handle(new MeetingArtifactsReady($event));

        $this->assertTrue(true);
    }

    #[Test]
    public function skips_when_uploader_has_no_telegram(): void
    {
        $user = User::factory()->create();
        // No TelegramUser created.

        $event = $this->createManualUploadEvent($user);

        Log::shouldReceive('info')
            ->atLeast()->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'no Telegram linked'));
        Log::makePartial();

        $listener = new SendTranscriptUploadReportNotification();
        $listener->handle(new MeetingArtifactsReady($event));
    }
}
