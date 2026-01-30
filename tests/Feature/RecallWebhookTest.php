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
            'calendar_event_id' => $this->calendarEvent->id,
            'external_id' => 'test-bot-123',
            'deduplication_key' => 'test-dedup-key',
        ]);
    }

    /** @test */
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

    /** @test */
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

        $job->handle(app(\App\Services\RecallTranscriptParser::class));

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

    /** @test */
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
        $parseJob->handle(app(\App\Services\RecallTranscriptParser::class));

        // Проверяем, что TranscriptParsed событие было dispatched
        Event::assertDispatched(TranscriptParsed::class);

        // Шаг 4: Обрабатываем событие TranscriptParsed вручную через слушателя
        $transcriptParsedEvent = Event::dispatched(TranscriptParsed::class)[0][0];
        $listener = new \App\Listeners\GenerateFollowup();
        $listener->handle($transcriptParsedEvent);

        // Проверяем, что GenerateFollowupJob был создан для команды пользователя
        Queue::assertPushed(GenerateFollowupJob::class, function ($job) {
            return $job->calendarEvent->id === $this->calendarEvent->id
                && $job->user->id === $this->user->id;
        });
    }

    /** @test */
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

    /** @test */
    public function webhook_returns_error_for_unsupported_event()
    {
        $webhookPayload = [
            'event' => 'unknown.event',
            'data' => []
        ];

        $response = $this->postJson('/api/v1/recall/webhook', $webhookPayload);

        $response->assertStatus(500);
    }

    /** @test */
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
}
