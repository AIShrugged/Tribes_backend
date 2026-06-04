<?php

namespace Tests\Feature;

use App\Events\TranscriptParsed;
use App\Jobs\GenerateFollowupJob;
use App\Jobs\ParseTranscriptJob;
use App\Models\Bot;
use App\Models\CalendarEvent;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Source;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecallWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected CalendarEvent $calendarEvent;
    protected Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        // Создаем организацию
        $organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
        ]);

        // Создаем пользователя
        $this->user = User::factory()->create();
        $organization->users()->attach($this->user, ['role' => 'employee']);

        // Создаем методологию
        $methodology = Methodology::create([
            'name' => 'Test Methodology',
            'text' => 'Test methodology text',
            'scheme' => '{}',
            'organization_id' => $organization->id,
        ]);

        // Создаем команду
        $team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'organization_id' => $organization->id,
            'methodology_id' => $methodology->id,
        ]);
        $team->users()->attach($this->user);

        // Создаем source
        $source = Source::create([
            'user_id' => $this->user->id,
            'type' => 'google_calendar',
            'external_id' => 'test-source-id',
            'identity' => 'test@example.com',
        ]);

        // Создаем календарное событие
        $this->calendarEvent = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'test-event-id',
            'platform' => 'google_meet',
            'title' => 'Test Meeting',
            'url' => 'https://meet.google.com/test',
            'description' => 'Test meeting description',
            'starts_at' => now(),
            'ends_at' => now()->addHour(),
            'required_bot' => false,
        ]);

        // Создаем бота
        $this->bot = Bot::create([
            'external_id' => 'test-bot-123',
            'deduplication_key' => 'test-dedup-key',
            'meeting_url' => 'https://meet.google.com/test',
            'is_active' => true,
        ]);

        $this->calendarEvent->update(['bot_id' => $this->bot->id]);
    }

    #[Test]
    public function webhook_receives_transcript_done_event_and_dispatches_parse_job()
    {
        Queue::fake();

        // Мокаем HTTP запрос к Recall API
        Http::fake([
            'https://us-west-2.recall.ai/api/v1/bot/test-bot-123/' => Http::response([
                'id' => 'test-bot-123',
                'recordings' => [
                    [
                        'media_shortcuts' => [
                            'transcript' => [
                                'data' => [
                                    'download_url' => 'https://example.com/transcript.json'
                                ]
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        // Формируем payload вебхука
        $webhookPayload = [
            'event' => 'transcript.done',
            'data' => [
                'bot' => [
                    'id' => 'test-bot-123'
                ]
            ]
        ];

        // Отправляем вебхук
        $response = $this->postJson('/api/v1/recall/webhook', $webhookPayload);

        $response->assertStatus(200);

        // Проверяем, что ParseTranscriptJob был создан
        Queue::assertPushed(ParseTranscriptJob::class, function ($job) {
            return $job->calendarEvent->id === $this->calendarEvent->id;
        });
    }

    #[Test]
    public function calendar_sync_event_marks_meeting_as_required_bot_and_schedules_it(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                return Http::response([
                    'bots' => [
                        [
                            'bot_id' => 'bot-sync-1',
                            'deduplication_key' => md5('recall-event-1'),
                        ],
                    ],
                ], 200);
            }

            return Http::response([
                'results' => [
                    [
                        'id' => 'recall-event-1',
                        'meeting_platform' => 'google_meet',
                        'meeting_url' => 'https://meet.google.com/sync-test',
                        'start_time' => now()->addHour()->toIso8601String(),
                        'end_time' => now()->addHours(2)->toIso8601String(),
                        'raw' => [
                            'summary' => 'Synced meeting',
                            'description' => 'Meeting from Recall sync_events webhook',
                            'attendees' => [],
                        ],
                    ],
                ],
            ], 200);
        });

        $source = Source::create([
            'user_id' => $this->user->id,
            'type' => 'google_calendar',
            'external_id' => 'sync-source-id',
            'identity' => 'sync@example.com',
        ]);

        $event = CalendarEvent::create([
            'platform' => 'google_meet',
            'title' => 'Synced meeting',
            'url' => 'https://meet.google.com/sync-test',
            'description' => 'Meeting from Recall sync_events webhook',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
            'required_bot' => false,
        ]);

        $event->sources()->attach($source->id, [
            'external_id' => 'recall-event-1',
            'required_bot' => false,
        ]);

        $response = $this->postJson('/api/v1/recall/webhook', [
            'event' => 'calendar.sync_events',
            'data' => [
                'calendar_id' => $source->external_id,
                'last_updated_ts' => now()->subMinute()->toIso8601String(),
            ],
        ]);

        $response->assertOk();

        $event->refresh();

        $this->assertTrue($event->isRequiredBot());
        $this->assertNotNull($event->bot_id);
        $this->assertDatabaseHas('calendar_event_source', [
            'calendar_event_id' => $event->id,
            'source_id' => $source->id,
            'required_bot' => true,
        ]);
        $this->assertDatabaseHas('bots', [
            'external_id' => 'bot-sync-1',
            'meeting_url' => 'https://meet.google.com/sync-test',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function calendar_update_event_resyncs_meeting_time_and_schedules_bot(): void
    {
        $baseTime = now()->startOfSecond();
        $existingStartsAt = $baseTime->copy()->addHour();
        $existingEndsAt = $baseTime->copy()->addHours(2);
        $updatedStartsAt = $baseTime->copy()->addHours(3);
        $updatedEndsAt = $baseTime->copy()->addHours(4);

        Http::fake(function ($request) use ($updatedStartsAt, $updatedEndsAt) {
            if (str_contains($request->url(), '/api/v2/calendars/')) {
                return Http::response(['status' => 'connected'], 200);
            }

            if ($request->method() === 'POST') {
                return Http::response([
                    'bots' => [
                        [
                            'bot_id' => 'bot-update-1',
                            'deduplication_key' => md5('recall-update-event-1'),
                        ],
                    ],
                ], 200);
            }

            return Http::response([
                'results' => [
                    [
                        'id' => 'recall-update-event-1',
                        'meeting_platform' => 'google_meet',
                        'meeting_url' => 'https://meet.google.com/update-test',
                        'start_time' => $updatedStartsAt->toIso8601String(),
                        'end_time' => $updatedEndsAt->toIso8601String(),
                        'raw' => [
                            'summary' => 'Updated meeting',
                            'description' => 'Meeting after calendar.update webhook',
                            'attendees' => [],
                        ],
                    ],
                ],
            ], 200);
        });

        $source = Source::create([
            'user_id' => $this->user->id,
            'type' => 'google_calendar',
            'external_id' => 'update-calendar-id',
            'identity' => 'update@example.com',
        ]);

        $existingEvent = CalendarEvent::create([
            'platform' => 'google_meet',
            'title' => 'Updated meeting',
            'url' => 'https://meet.google.com/update-test',
            'description' => 'Meeting before calendar.update webhook',
            'starts_at' => $existingStartsAt,
            'ends_at' => $existingEndsAt,
            'required_bot' => false,
        ]);

        $existingEvent->sources()->attach($source->id, [
            'external_id' => 'recall-update-event-1',
            'required_bot' => false,
        ]);

        $response = $this->postJson('/api/v1/recall/webhook', [
            'event' => 'calendar.update',
            'data' => [
                'calendar_id' => $source->external_id,
            ],
        ]);

        $response->assertOk();

        $updatedEvent = CalendarEvent::query()
            ->where('url', 'https://meet.google.com/update-test')
            ->where('starts_at', $updatedStartsAt)
            ->firstOrFail();

        $this->assertTrue($updatedEvent->isRequiredBot());
        $this->assertNotNull($updatedEvent->bot_id);
        $this->assertDatabaseHas('calendar_event_source', [
            'calendar_event_id' => $updatedEvent->id,
            'source_id' => $source->id,
            'required_bot' => true,
        ]);
        $this->assertDatabaseHas('bots', [
            'external_id' => 'bot-update-1',
            'meeting_url' => 'https://meet.google.com/update-test',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function calendar_update_event_deactivates_upcoming_bots_when_calendar_is_disconnected(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/v2/calendars/')) {
                return Http::response(['status' => 'disconnected'], 200);
            }

            return Http::response([], 500);
        });

        $source = Source::create([
            'user_id' => $this->user->id,
            'type' => 'google_calendar',
            'external_id' => 'disconnected-calendar-id',
            'identity' => 'disconnected@example.com',
            'is_connected' => true,
        ]);

        $event = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'disconnected-event-id',
            'platform' => 'google_meet',
            'title' => 'Disconnected meeting',
            'url' => 'https://meet.google.com/disconnected-test',
            'description' => '',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
        ]);

        $event->sources()->attach($source->id, [
            'external_id' => 'disconnected-event-id',
            'required_bot' => true,
        ]);

        $bot = Bot::create([
            'external_id' => 'bot-disconnected-1',
            'deduplication_key' => 'bot-disconnected-key',
            'meeting_url' => $event->url,
            'is_active' => true,
        ]);
        $event->update(['bot_id' => $bot->id]);

        $response = $this->postJson('/api/v1/recall/webhook', [
            'event' => 'calendar.update',
            'data' => [
                'calendar_id' => $source->external_id,
            ],
        ]);

        $response->assertOk();

        $this->assertFalse((bool) $source->fresh()->is_connected);
        $this->assertFalse((bool) $bot->fresh()->is_active);
        $this->assertTrue($event->fresh()->isRequiredBot());

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    #[Test]
    public function calendar_sync_event_updates_existing_future_event_when_meeting_is_rescheduled(): void
    {
        $oldStartsAt = now()->addHours(2)->startOfSecond();
        $oldEndsAt   = now()->addHours(3)->startOfSecond();
        $newStartsAt = now()->addHours(5)->startOfSecond();
        $newEndsAt   = now()->addHours(6)->startOfSecond();

        $source = Source::create([
            'user_id'     => $this->user->id,
            'type'        => 'google_calendar',
            'external_id' => 'reschedule-calendar-id',
            'identity'    => 'reschedule@example.com',
        ]);

        // Существующий ивент со старым временем
        $existingEvent = CalendarEvent::create([
            'platform'    => 'google_meet',
            'title'       => 'Rescheduled meeting',
            'url'         => 'https://meet.google.com/reschedule-test',
            'description' => 'Original description',
            'starts_at'   => $oldStartsAt,
            'ends_at'     => $oldEndsAt,
        ]);

        $existingEvent->sources()->attach($source->id, [
            'external_id'  => 'gc-event-fixed-id',
            'required_bot' => false,
        ]);

        $deduplicationKey = md5('gc-event-fixed-id');

        Http::fake([
            // Более специфичный паттерн должен идти первым — fnmatch(*) матчит слеши
            'https://us-west-2.recall.ai/api/v2/calendar-events/*/bot/' => Http::response([
                'bots' => [
                    ['bot_id' => 'bot-reschedule-1', 'deduplication_key' => $deduplicationKey],
                ],
            ], 200),
            'https://us-west-2.recall.ai/api/v2/calendar-events/*' => Http::response([
                'results' => [
                    [
                        'id'               => 'gc-event-fixed-id', // тот же Google Calendar event ID
                        'meeting_platform' => 'google_meet',
                        'meeting_url'      => 'https://meet.google.com/reschedule-test',
                        'start_time'       => $newStartsAt->toIso8601String(),
                        'end_time'         => $newEndsAt->toIso8601String(),
                        'raw' => [
                            'summary'     => 'Rescheduled meeting',
                            'description' => 'Original description',
                            'attendees'   => [],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/recall/webhook', [
            'event' => 'calendar.sync_events',
            'data' => [
                'calendar_id'    => $source->external_id,
                'last_updated_ts' => now()->subMinute()->toIso8601String(),
            ],
        ]);

        $response->assertOk();

        // Должен существовать только один ивент (не дубликат)
        $this->assertSame(
            1,
            CalendarEvent::where('url', 'https://meet.google.com/reschedule-test')->count(),
            'Должен быть ровно один CalendarEvent — дубликат не должен создаваться'
        );

        $existingEvent->refresh();
        $this->assertTrue(
            $existingEvent->starts_at->eq($newStartsAt),
            'starts_at существующего ивента должен обновиться на новое время'
        );
        $this->assertTrue(
            $existingEvent->ends_at->eq($newEndsAt),
            'ends_at существующего ивента должен обновиться на новое время'
        );
    }

    #[Test]
    public function calendar_sync_event_deletes_local_meeting_when_recall_marks_it_deleted(): void
    {
        $source = Source::create([
            'user_id'     => $this->user->id,
            'type'        => 'google_calendar',
            'external_id' => 'deleted-calendar-id',
            'identity'    => 'deleted@example.com',
        ]);

        $event = CalendarEvent::create([
            'source_id'   => $source->id,
            'external_id' => 'deleted-recall-event-id',
            'platform'    => 'google_meet',
            'title'       => 'Deleted meeting',
            'url'         => 'https://meet.google.com/deleted-test',
            'description' => 'This meeting was deleted in the source calendar.',
            'starts_at'   => now()->addHours(2),
            'ends_at'     => now()->addHours(3),
        ]);

        $event->sources()->attach($source->id, [
            'external_id'  => 'deleted-recall-event-id',
            'required_bot' => true,
        ]);

        $bot = Bot::create([
            'external_id'       => 'bot-deleted-1',
            'deduplication_key' => 'bot-deleted-key',
            'meeting_url'       => $event->url,
            'is_active'         => true,
        ]);
        $event->update(['bot_id' => $bot->id]);

        Http::fake([
            'https://us-west-2.recall.ai/api/v2/calendar-events/*' => Http::response([
                'results' => [
                    [
                        'id' => 'deleted-recall-event-id',
                        'is_deleted' => true,
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/recall/webhook', [
            'event' => 'calendar.sync_events',
            'data' => [
                'calendar_id'     => $source->external_id,
                'last_updated_ts' => now()->subMinute()->toIso8601String(),
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseMissing('calendar_events', [
            'id' => $event->id,
        ]);
        $this->assertDatabaseMissing('calendar_event_source', [
            'calendar_event_id' => $event->id,
            'source_id' => $source->id,
        ]);
        $this->assertFalse((bool) $bot->fresh()->is_active);

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    #[Test]
    public function calendar_update_event_keeps_local_meetings_when_recall_returns_empty_results(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/v2/calendars/')) {
                return Http::response(['status' => 'connected'], 200);
            }

            return Http::response([
                'results' => [],
            ], 200);
        });

        $source = Source::create([
            'user_id'     => $this->user->id,
            'type'        => 'google_calendar',
            'external_id' => 'missing-calendar-id',
            'identity'    => 'missing@example.com',
        ]);

        $futureEvent = CalendarEvent::create([
            'source_id'   => $source->id,
            'external_id' => 'missing-future-event-id',
            'platform'    => 'google_meet',
            'title'       => 'Future missing meeting',
            'url'         => 'https://meet.google.com/missing-future-test',
            'description' => 'This future meeting is no longer returned by Recall.',
            'starts_at'   => now()->addHours(2),
            'ends_at'     => now()->addHours(3),
        ]);

        $futureEvent->sources()->attach($source->id, [
            'external_id'  => 'missing-future-event-id',
            'required_bot' => true,
        ]);

        $pastEvent = CalendarEvent::create([
            'source_id'   => $source->id,
            'external_id' => 'missing-past-event-id',
            'platform'    => 'google_meet',
            'title'       => 'Past missing meeting',
            'url'         => 'https://meet.google.com/missing-past-test',
            'description' => 'Past meetings stay as history.',
            'starts_at'   => now()->subHours(3),
            'ends_at'     => now()->subHours(2),
        ]);

        $pastEvent->sources()->attach($source->id, [
            'external_id'  => 'missing-past-event-id',
            'required_bot' => true,
        ]);

        $response = $this->postJson('/api/v1/recall/webhook', [
            'event' => 'calendar.update',
            'data' => [
                'calendar_id' => $source->external_id,
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('calendar_events', [
            'id' => $futureEvent->id,
        ]);
        $this->assertDatabaseHas('calendar_events', [
            'id' => $pastEvent->id,
        ]);
        $this->assertDatabaseHas('calendar_event_source', [
            'calendar_event_id' => $pastEvent->id,
            'source_id' => $source->id,
            'external_id' => 'missing-past-event-id',
        ]);
    }

    #[Test]
    public function calendar_update_event_deletes_local_meeting_when_recall_marks_it_deleted(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/v2/calendars/')) {
                return Http::response(['status' => 'connected'], 200);
            }

            return Http::response([
                'results' => [
                    [
                        'id' => 'update-deleted-recall-event-id',
                        'is_deleted' => true,
                    ],
                ],
            ], 200);
        });

        $source = Source::create([
            'user_id'     => $this->user->id,
            'type'        => 'google_calendar',
            'external_id' => 'update-deleted-calendar-id',
            'identity'    => 'update-deleted@example.com',
        ]);

        $event = CalendarEvent::create([
            'source_id'   => $source->id,
            'external_id' => 'update-deleted-recall-event-id',
            'platform'    => 'google_meet',
            'title'       => 'Update deleted meeting',
            'url'         => 'https://meet.google.com/update-deleted-test',
            'description' => 'This meeting was deleted in the source calendar.',
            'starts_at'   => now()->addHours(2),
            'ends_at'     => now()->addHours(3),
        ]);

        $event->sources()->attach($source->id, [
            'external_id'  => 'update-deleted-recall-event-id',
            'required_bot' => true,
        ]);

        $response = $this->postJson('/api/v1/recall/webhook', [
            'event' => 'calendar.update',
            'data' => [
                'calendar_id' => $source->external_id,
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseMissing('calendar_events', [
            'id' => $event->id,
        ]);
        $this->assertDatabaseMissing('calendar_event_source', [
            'calendar_event_id' => $event->id,
            'source_id' => $source->id,
        ]);
    }

    #[Test]
    public function calendar_sync_event_keeps_local_meetings_when_recall_returns_empty_results(): void
    {
        Http::fake([
            'https://us-west-2.recall.ai/api/v2/calendar-events/*' => Http::response([
                'results' => [],
            ], 200),
        ]);

        $source = Source::create([
            'user_id'     => $this->user->id,
            'type'        => 'google_calendar',
            'external_id' => 'sync-missing-calendar-id',
            'identity'    => 'sync-missing@example.com',
        ]);

        $futureEvent = CalendarEvent::create([
            'source_id'   => $source->id,
            'external_id' => 'sync-missing-future-event-id',
            'platform'    => 'google_meet',
            'title'       => 'Sync future missing meeting',
            'url'         => 'https://meet.google.com/sync-missing-future-test',
            'description' => 'This future meeting is no longer returned by Recall sync.',
            'starts_at'   => now()->addHours(2),
            'ends_at'     => now()->addHours(3),
        ]);

        $futureEvent->sources()->attach($source->id, [
            'external_id'  => 'sync-missing-future-event-id',
            'required_bot' => true,
        ]);

        $pastEvent = CalendarEvent::create([
            'source_id'   => $source->id,
            'external_id' => 'sync-missing-past-event-id',
            'platform'    => 'google_meet',
            'title'       => 'Sync past missing meeting',
            'url'         => 'https://meet.google.com/sync-missing-past-test',
            'description' => 'Past meetings stay as history.',
            'starts_at'   => now()->subHours(3),
            'ends_at'     => now()->subHours(2),
        ]);

        $pastEvent->sources()->attach($source->id, [
            'external_id'  => 'sync-missing-past-event-id',
            'required_bot' => true,
        ]);

        $response = $this->postJson('/api/v1/recall/webhook', [
            'event' => 'calendar.sync_events',
            'data' => [
                'calendar_id'     => $source->external_id,
                'last_updated_ts' => now()->subMinute()->toIso8601String(),
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('calendar_events', [
            'id' => $futureEvent->id,
        ]);
        $this->assertDatabaseHas('calendar_events', [
            'id' => $pastEvent->id,
        ]);
        $this->assertDatabaseHas('calendar_event_source', [
            'calendar_event_id' => $pastEvent->id,
            'source_id' => $source->id,
            'external_id' => 'sync-missing-past-event-id',
        ]);
    }

    #[Test]
    public function bot_require_recreates_active_recall_bot_when_required_bot_is_true(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'DELETE') {
                return Http::response(['status' => 'deleted'], 200);
            }

            return Http::response([
                'bots' => [
                    [
                        'bot_id' => 'bot-refresh-1',
                        'deduplication_key' => md5('test-event-id'),
                    ],
                ],
            ], 200);
        });

        $source = Source::firstWhere('user_id', $this->user->id);
        $this->calendarEvent->update(['creator_user_id' => $this->user->id]);
        $this->calendarEvent->sources()->syncWithoutDetaching([
            $source->id => ['external_id' => 'test-event-id', 'required_bot' => false],
        ]);

        $this->actingAs($this->user);

        $response = $this->postJson('/api/v1/calendar-events/' . $this->calendarEvent->id . '/bot/require', [
            'required_bot' => true,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('bots', [
            'external_id' => 'test-bot-123',
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('bots', [
            'external_id' => 'bot-refresh-1',
            'meeting_url' => 'https://meet.google.com/test',
            'is_active' => true,
        ]);

        $this->assertSame('bot-refresh-1', $this->calendarEvent->fresh()->bot?->external_id);
    }

    #[Test]
    public function bot_join_now_creates_direct_recall_bot_for_running_meeting(): void
    {
        Http::fake([
            'https://us-west-2.recall.ai/api/v1/bot/' => Http::response([
                'id' => 'bot-direct-1',
            ], 201),
        ]);

        $source = Source::firstWhere('user_id', $this->user->id);

        $this->calendarEvent->update([
            'creator_user_id' => $this->user->id,
        ]);
        $this->calendarEvent->sources()->syncWithoutDetaching([
            $source->id => [
                'external_id' => 'test-event-id',
                'required_bot' => false,
            ],
        ]);

        $this->actingAs($this->user);

        $response = $this->postJson('/api/v1/calendar-events/' . $this->calendarEvent->id . '/bot/join-now');

        $response->assertOk();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://us-west-2.recall.ai/api/v1/bot/'
                && $request['meeting_url'] === 'https://meet.google.com/test'
                && $request['bot_name'] === 'Tribes Notetaker'
                && $request['metadata']['calendar_event_id'] === (string) $this->calendarEvent->id;
        });

        $this->assertDatabaseHas('calendar_event_source', [
            'calendar_event_id' => $this->calendarEvent->id,
            'source_id' => $source->id,
            'required_bot' => true,
        ]);

        $this->assertDatabaseHas('bots', [
            'external_id' => 'test-bot-123',
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('bots', [
            'external_id' => 'bot-direct-1',
            'meeting_url' => 'https://meet.google.com/test',
            'is_active' => true,
        ]);

        $this->assertSame('bot-direct-1', $this->calendarEvent->fresh()->bot?->external_id);
    }

    #[Test]
    public function parse_transcript_job_creates_transcript_entries_and_dispatches_event()
    {
        Event::fake();
        Queue::fake();

        // Мокаем транскрипт JSON
        $transcriptJson = [
            [
                'participant' => [
                    'name' => 'Speaker 1',
                ],
                'words' => [
                    [
                        'text' => 'Один',
                        'start_timestamp' => [
                            'relative' => 0.0,
                            'absolute' => '2024-01-01T00:00:00Z'
                        ],
                        'end_timestamp' => [
                            'relative' => 1.0,
                            'absolute' => '2024-01-01T00:00:01Z'
                        ]
                    ],
                    [
                        'text' => 'Два',
                        'start_timestamp' => [
                            'relative' => 1.0,
                            'absolute' => '2024-01-01T00:00:01Z'
                        ],
                        'end_timestamp' => [
                            'relative' => 2.0,
                            'absolute' => '2024-01-01T00:00:02Z'
                        ]
                    ],
                    [
                        'text' => 'Три.',
                        'start_timestamp' => [
                            'relative' => 2.0,
                            'absolute' => '2024-01-01T00:00:02Z'
                        ],
                        'end_timestamp' => [
                            'relative' => 3.0,
                            'absolute' => '2024-01-01T00:00:03Z'
                        ]
                    ]
                ]
            ],
            [
                'participant' => [
                    'name' => 'Speaker 2',
                ],
                'words' => [
                    [
                        'text' => 'Один',
                        'start_timestamp' => [
                            'relative' => 3.0,
                            'absolute' => '2024-01-01T00:00:03Z'
                        ],
                        'end_timestamp' => [
                            'relative' => 4.0,
                            'absolute' => '2024-01-01T00:00:04Z'
                        ]
                    ],
                    [
                        'text' => 'Два',
                        'start_timestamp' => [
                            'relative' => 4.0,
                            'absolute' => '2024-01-01T00:00:04Z'
                        ],
                        'end_timestamp' => [
                            'relative' => 5.0,
                            'absolute' => '2024-01-01T00:00:05Z'
                        ]
                    ],
                    [
                        'text' => 'Три.',
                        'start_timestamp' => [
                            'relative' => 5.0,
                            'absolute' => '2024-01-01T00:00:05Z'
                        ],
                        'end_timestamp' => [
                            'relative' => 6.0,
                            'absolute' => '2024-01-01T00:00:06Z'
                        ]
                    ]
                ]
            ],
        ];

        // Мокаем HTTP запрос для скачивания транскрипта
        Http::fake([
            'https://example.com/transcript.json' => Http::response($transcriptJson, 200)
        ]);

        // Создаем и запускаем job
        $job = new ParseTranscriptJob(
            $this->calendarEvent,
            'https://example.com/transcript.json'
        );

        $job->handle(
            app(\App\Services\RecallTranscriptParser::class),
            app(\App\Services\Transcript\TranscriptPersistenceService::class),
        );

        // Проверяем, что участники созданы
        $this->assertDatabaseHas('participants', [
            'calendar_event_id' => $this->calendarEvent->id,
            'name' => 'Speaker 1'
        ]);

        $this->assertDatabaseHas('participants', [
            'calendar_event_id' => $this->calendarEvent->id,
            'name' => 'Speaker 2'
        ]);

        // Проверяем, что записи транскрипта созданы
        $this->assertDatabaseCount('transcript_entries', 2);

        // Проверяем, что событие TranscriptParsed было dispatched
        Event::assertDispatched(TranscriptParsed::class, function ($event) {
            return $event->calendarEvent->id === $this->calendarEvent->id;
        });
    }

    #[Test]
    public function full_webhook_to_followup_generation_flow()
    {
        Queue::fake();
        Event::fake();

        // Шаг 1: Мокаем HTTP для получения информации о боте
        Http::fake([
            'https://us-west-2.recall.ai/api/v1/bot/test-bot-123/' => Http::response([
                'id' => 'test-bot-123',
                'recordings' => [
                    [
                        'media_shortcuts' => [
                            'transcript' => [
                                'data' => [
                                    'download_url' => 'https://example.com/transcript.json'
                                ]
                            ]
                        ]
                    ]
                ]
            ], 200),
            'https://example.com/transcript.json' => Http::response([
                [
                    'participant' => [
                        'name' => 'John Doe',
                    ],
                    'words' => [
                        [
                            'text' => 'Test',
                            'start_timestamp' => [
                                'relative' => 0.0,
                                'absolute' => '2024-01-01T00:00:00Z'
                            ],
                            'end_timestamp' => [
                                'relative' => 1.0,
                                'absolute' => '2024-01-01T00:00:01Z'
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        // Шаг 2: Отправляем вебхук transcript.done
        $webhookPayload = [
            'event' => 'transcript.done',
            'data' => [
                'bot' => ['id' => 'test-bot-123']
            ]
        ];

        $response = $this->postJson('/api/v1/recall/webhook', $webhookPayload);
        $response->assertStatus(200);

        // Проверяем, что ParseTranscriptJob был создан
        Queue::assertPushed(ParseTranscriptJob::class);

        // Шаг 3: Выполняем ParseTranscriptJob
        $parseJob = Queue::pushedJobs()[ParseTranscriptJob::class][0]['job'];
        $parseJob->handle(
            app(\App\Services\RecallTranscriptParser::class),
            app(\App\Services\Transcript\TranscriptPersistenceService::class),
        );

        // Проверяем, что TranscriptParsed событие было dispatched
        Event::assertDispatched(TranscriptParsed::class);

        // Шаг 4: Обрабатываем событие TranscriptParsed вручную через слушателя
        $transcriptParsedEvent = Event::dispatched(TranscriptParsed::class)[0][0];
        $listener = app(\App\Listeners\GenerateFollowup::class);
        $listener->handle($transcriptParsedEvent);

        // Проверяем, что GenerateFollowupJob был создан для команды пользователя
        Queue::assertPushed(GenerateFollowupJob::class, function ($job) {
            return $job->calendarEvent->id === $this->calendarEvent->id
                && $job->user->id === $this->user->id;
        });
    }

    #[Test]
    public function webhook_returns_error_for_unknown_bot()
    {
        $webhookPayload = [
            'event' => 'transcript.done',
            'data' => [
                'bot' => ['id' => 'unknown-bot-id']
            ]
        ];

        $response = $this->postJson('/api/v1/recall/webhook', $webhookPayload);

        // Должен вернуть ошибку или логировать
        $response->assertStatus(500);
    }

    #[Test]
    public function webhook_returns_error_for_unsupported_event()
    {
        $webhookPayload = [
            'event' => 'unknown.event',
            'data' => []
        ];

        $response = $this->postJson('/api/v1/recall/webhook', $webhookPayload);

        $response->assertStatus(500);
    }

    #[Test]
    public function webhook_handles_recall_api_failure_gracefully()
    {
        Queue::fake();

        // Мокаем неудачный HTTP запрос к Recall API
        Http::fake([
            'https://us-west-2.recall.ai/api/v1/bot/test-bot-123/' => Http::response([
                'message' => 'Bot not found'
            ], 404)
        ]);

        $webhookPayload = [
            'event' => 'transcript.done',
            'data' => [
                'bot' => ['id' => 'test-bot-123']
            ]
        ];

        $response = $this->postJson('/api/v1/recall/webhook', $webhookPayload);

        // Должен вернуть ошибку
        $response->assertStatus(500);

        // Job не должен быть создан
        Queue::assertNotPushed(ParseTranscriptJob::class);
    }

    #[Test]
    public function calendar_sync_from_multiple_sources_for_same_meeting_schedules_only_one_bot(): void
    {
        $secondUser = User::factory()->create();
        $organization = Organization::query()->firstOrFail();
        $organization->users()->attach($secondUser, ['role' => 'employee']);

        $sourceA = Source::create([
            'user_id'     => $this->user->id,
            'type'        => 'google_calendar',
            'external_id' => 'calendar-a',
            'identity'    => 'calendar-a@example.com',
        ]);

        $sourceB = Source::create([
            'user_id'     => $secondUser->id,
            'type'        => 'google_calendar',
            'external_id' => 'calendar-b',
            'identity'    => 'calendar-b@example.com',
        ]);

        $meetingStart  = now()->addHours(3)->startOfSecond();
        $meetingEnd    = $meetingStart->copy()->addHour();
        $meetingUrl    = 'https://meet.google.com/multi-source-test';
        $botScheduleCalls = 0;

        Http::fake(function ($request) use ($meetingStart, $meetingEnd, $meetingUrl, &$botScheduleCalls) {
            $url = $request->url();

            if ($request->method() === 'GET' && str_starts_with($url, 'https://us-west-2.recall.ai/api/v2/calendar-events/')) {
                $calendarId = $request->data()['calendar_id'] ?? null;
                $externalId = match ($calendarId) {
                    'calendar-a' => 'recall-event-a',
                    'calendar-b' => 'recall-event-b',
                    default      => 'recall-event-unknown',
                };

                return Http::response([
                    'results' => [[
                        'id'               => $externalId,
                        'meeting_platform' => 'google_meet',
                        'meeting_url'      => $meetingUrl,
                        'start_time'       => $meetingStart->toIso8601String(),
                        'end_time'         => $meetingEnd->toIso8601String(),
                        'raw'              => [
                            'summary'     => 'Shared meeting',
                            'description' => '',
                            'attendees'   => [],
                        ],
                    ]],
                ], 200);
            }

            if ($request->method() === 'POST' && preg_match('#https://us-west-2\.recall\.ai/api/v2/calendar-events/([^/]+)/bot/#', $url) === 1) {
                $botScheduleCalls++;

                return Http::response([
                    'bots' => [[
                        'bot_id'            => 'bot-shared-1',
                        'deduplication_key' => md5('recall-event-a'),
                    ]],
                ], 200);
            }

            return Http::response([], 200);
        });

        // Trigger a calendar sync webhook for source A (first user, required_bot=true)
        $webhookPayloadA = [
            'event' => 'calendar.sync_events',
            'data'  => [
                'calendar_id'    => 'calendar-a',
                'last_updated_ts' => now()->subMinute()->toIso8601String(),
            ],
        ];

        $this->postJson('/api/v1/recall/webhook', $webhookPayloadA)->assertSuccessful();

        // Simulate the same meeting syncing for source B (second user)
        $webhookPayloadB = [
            'event' => 'calendar.sync_events',
            'data'  => [
                'calendar_id'    => 'calendar-b',
                'last_updated_ts' => now()->subMinute()->toIso8601String(),
            ],
        ];

        $this->postJson('/api/v1/recall/webhook', $webhookPayloadB)->assertSuccessful();

        // Only one bot should have been scheduled with Recall
        $this->assertSame(1, $botScheduleCalls, 'Expected exactly one Recall bot scheduling call for a shared meeting');

        // One bot from setUp + one for the shared meeting (not duplicated per source)
        $this->assertDatabaseCount('bots', 2);

        $event = CalendarEvent::query()->where('url', $meetingUrl)->firstOrFail();
        $this->assertNotNull($event->bot_id);
        $this->assertDatabaseHas('bots', [
            'external_id' => 'bot-shared-1',
            'meeting_url' => $meetingUrl,
            'is_active'   => true,
        ]);
    }

    #[Test]
    public function calendar_sync_schedules_bot_with_host_external_id_for_shared_meeting(): void
    {
        $secondUser = User::factory()->create(['email' => 'test2@gmail.com']);
        $organization = Organization::query()->firstOrFail();
        $organization->users()->attach($secondUser, ['role' => 'employee']);

        $sourceA = Source::create([
            'user_id'     => $this->user->id,
            'type'        => 'google_calendar',
            'external_id' => 'calendar-a',
            'identity'    => 'test1@gmail.com',
        ]);

        $sourceB = Source::create([
            'user_id'     => $secondUser->id,
            'type'        => 'google_calendar',
            'external_id' => 'calendar-b',
            'identity'    => 'test2@gmail.com',
        ]);

        $meetingStart = now()->addHours(3)->startOfSecond();
        $meetingEnd   = $meetingStart->copy()->addHour();
        $meetingUrl   = 'https://meet.google.com/shared-required-source-test';
        $scheduledCalendarEventIds = [];

        $event = CalendarEvent::create([
            'source_id'       => $sourceA->id,
            'creator_user_id' => $secondUser->id,
            'external_id'     => 'some_external_id1',
            'platform'        => 'google_meet',
            'title'           => 'Shared meeting',
            'url'             => $meetingUrl,
            'description'     => '',
            'starts_at'       => $meetingStart,
            'ends_at'         => $meetingEnd,
        ]);

        $event->sources()->attach($sourceA->id, [
            'external_id'  => 'some_external_id1',
            'required_bot' => false,
        ]);

        Http::fake(function ($request) use ($meetingStart, $meetingEnd, $meetingUrl, &$scheduledCalendarEventIds) {
            $url = $request->url();

            if ($request->method() === 'GET' && str_starts_with($url, 'https://us-west-2.recall.ai/api/v2/calendar-events/')) {
                $calendarId = $request->data()['calendar_id'] ?? null;
                $externalId = $calendarId === 'calendar-b' ? 'some_external_id2' : 'some_external_id1';

                return Http::response([
                    'results' => [[
                        'id'               => $externalId,
                        'meeting_platform' => 'google_meet',
                        'meeting_url'      => $meetingUrl,
                        'start_time'       => $meetingStart->toIso8601String(),
                        'end_time'         => $meetingEnd->toIso8601String(),
                        'raw'              => [
                            'summary'     => 'Shared meeting',
                            'description' => '',
                            'creator'     => ['email' => 'test2@gmail.com'],
                            'attendees'   => [],
                        ],
                    ]],
                ], 200);
            }

            if ($request->method() === 'POST' && preg_match('#/api/v2/calendar-events/([^/]+)/bot/#', $url, $matches) === 1) {
                $scheduledCalendarEventIds[] = $matches[1];

                return Http::response([
                    'bots' => [[
                        'bot_id'            => 'bot-shared-required-source',
                        'deduplication_key' => md5('some_external_id2'),
                    ]],
                ], 200);
            }

            return Http::response([], 200);
        });

        $this->postJson('/api/v1/recall/webhook', [
            'event' => 'calendar.sync_events',
            'data'  => [
                'calendar_id'     => $sourceA->external_id,
                'last_updated_ts' => now()->subMinute()->toIso8601String(),
            ],
        ])->assertSuccessful();

        $this->assertSame([], $scheduledCalendarEventIds);
        $this->assertSame('some_external_id1', $event->fresh()->external_id);
        $this->assertFalse($event->fresh()->isRequiredBot());

        $this->postJson('/api/v1/recall/webhook', [
            'event' => 'calendar.sync_events',
            'data'  => [
                'calendar_id'     => $sourceB->external_id,
                'last_updated_ts' => now()->subMinute()->toIso8601String(),
            ],
        ])->assertSuccessful();

        $this->assertSame(['some_external_id2'], $scheduledCalendarEventIds);
        $this->assertSame('some_external_id2', $event->fresh()->external_id);
        $this->assertTrue($event->fresh()->isRequiredBot());
        $this->assertSame('some_external_id2', $event->fresh()->getRecallExternalId());
        $this->assertDatabaseHas('calendar_event_source', [
            'calendar_event_id' => $event->id,
            'source_id'         => $sourceB->id,
            'external_id'       => 'some_external_id2',
            'required_bot'      => true,
        ]);
        $this->assertDatabaseHas('bots', [
            'external_id' => 'bot-shared-required-source',
            'meeting_url' => $meetingUrl,
            'is_active'   => true,
        ]);
    }
}
