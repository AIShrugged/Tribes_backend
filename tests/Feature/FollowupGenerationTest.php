<?php

namespace Tests\Feature;

use App\Events\TranscriptParsed;
use App\Jobs\GenerateFollowupJob;
use App\Models\CalendarEvent;
use App\Models\Followup;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Source;
use App\Models\Team;
use App\Models\TranscriptEntry;
use App\Models\User;
use App\Services\Followup\FollowupService;
use App\Services\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FollowupGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Organization $organization;
    protected Team $team;
    protected CalendarEvent $calendarEvent;
    protected Methodology $methodology;

    protected function setUp(): void
    {
        parent::setUp();

        // Создаем организацию
        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
        ]);

        // Создаем пользователя
        $this->user = User::factory()->create();

        // Привязываем к организации
        $this->organization->users()->attach($this->user, ['role' => 'employee']);

        // Создаем методологию
        $this->methodology = Methodology::create([
            'name' => 'Test Methodology',
            'text' => 'Analyze the meeting and provide insights.',
            'scheme' => json_encode([
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string'],
                    'action_items' => ['type' => 'array'],
                ]
            ]),
            'organization_id' => $this->organization->id,
        ]);

        // Создаем команду с методологией
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'organization_id' => $this->organization->id,
            'methodology_id' => $this->methodology->id,
        ]);

        // Привязываем пользователя к команде
        $this->team->users()->attach($this->user);

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
    }

    #[Test]
    public function transcript_parsed_event_dispatches_followup_jobs_for_all_user_teams()
    {
        Queue::fake();

        // Создаем вторую команду для пользователя
        $team2 = Team::create([
            'name' => 'Second Team',
            'slug' => 'second-team',
            'organization_id' => $this->organization->id,
            'methodology_id' => $this->methodology->id,
        ]);
        $team2->users()->attach($this->user);

        // Диспатчим событие TranscriptParsed
        Event::dispatch(new TranscriptParsed($this->calendarEvent));

        // Проверяем, что job был создан для каждой команды
        Queue::assertPushed(GenerateFollowupJob::class, 2);

        Queue::assertPushed(GenerateFollowupJob::class, function ($job) {
            return $job->calendarEvent->id === $this->calendarEvent->id
                && $job->team->id === $this->team->id
                && $job->user->id === $this->user->id;
        });

        Queue::assertPushed(GenerateFollowupJob::class, function ($job) use ($team2) {
            return $job->calendarEvent->id === $this->calendarEvent->id
                && $job->team->id === $team2->id
                && $job->user->id === $this->user->id;
        });
    }

    #[Test]
    public function transcript_parsed_event_does_not_create_jobs_if_user_has_no_teams()
    {
        Queue::fake();

        // Отвязываем пользователя от команды
        $this->team->users()->detach($this->user);

        // Диспатчим событие
        Event::dispatch(new TranscriptParsed($this->calendarEvent));

        // Job не должен быть создан
        Queue::assertNotPushed(GenerateFollowupJob::class);
    }

    #[Test]
    public function followup_is_created_with_correct_data()
    {
        // Мокаем OpenRouter клиент
        $mockClient = Mockery::mock(OpenRouterClient::class);
        $mockClient->shouldReceive('chat')
            ->once()
            ->andReturn(json_encode([
                'summary' => 'Meeting summary',
                'action_items' => ['Task 1', 'Task 2']
            ]));

        $this->app->instance(OpenRouterClient::class, $mockClient);

        // Создаем транскрипт
        $participant = \App\Models\Participant::create([
            'calendar_event_id' => $this->calendarEvent->id,
            'name' => 'John Doe',
        ]);

        TranscriptEntry::create([
            'calendar_event_id' => $this->calendarEvent->id,
            'participant_id' => $participant->id,
            'text' => 'Hello everyone, let\'s discuss the project.',
            'start_relative' => 0.0,
            'end_relative' => 5.0,
            'start_absolute' => now(),
            'end_absolute' => now()->addSeconds(5),
        ]);

        // Вызываем сервис генерации
        $service = $this->app->make(FollowupService::class);
        $followup = $service->generate($this->calendarEvent, $this->team, $this->user);

        // Проверяем, что followup создан правильно
        $this->assertInstanceOf(Followup::class, $followup);
        $this->assertEquals($this->calendarEvent->id, $followup->calendar_event_id);
        $this->assertEquals($this->team->id, $followup->team_id);
        $this->assertEquals($this->user->id, $followup->user_id);
        $this->assertEquals($this->methodology->id, $followup->methodology_id);
        $this->assertEquals('done', $followup->status);

        // Проверяем содержимое
        $text = json_decode($followup->text, true);
        $this->assertArrayHasKey('summary', $text);
        $this->assertArrayHasKey('action_items', $text);
        $this->assertCount(2, $text['action_items']);
    }

    #[Test]
    public function followup_status_is_failed_when_openrouter_fails()
    {
        // Мокаем OpenRouter клиент с ошибкой
        $mockClient = Mockery::mock(OpenRouterClient::class);
        $mockClient->shouldReceive('chat')
            ->once()
            ->andThrow(new \Exception('API Error'));

        $this->app->instance(OpenRouterClient::class, $mockClient);

        // Вызываем сервис генерации
        $service = $this->app->make(FollowupService::class);
        $followup = $service->generate($this->calendarEvent, $this->team, $this->user);

        // Проверяем, что статус failed
        $this->assertEquals('failed', $followup->status);
        $this->assertEmpty($followup->text);
    }

    #[Test]
    public function followup_uses_team_methodology()
    {
        // Мокаем OpenRouter клиент
        $mockClient = Mockery::mock(OpenRouterClient::class);
        $mockClient->shouldReceive('chat')
            ->once()
            ->andReturn('{"test": "data"}');

        $this->app->instance(OpenRouterClient::class, $mockClient);

        // Вызываем сервис генерации
        $service = $this->app->make(FollowupService::class);
        $followup = $service->generate($this->calendarEvent, $this->team, $this->user);

        // Проверяем, что использована методология команды
        $this->assertEquals($this->methodology->id, $followup->methodology_id);
    }

    #[Test]
    public function transcript_is_built_correctly_from_entries()
    {
        // Создаем участников
        $participant1 = \App\Models\Participant::create([
            'calendar_event_id' => $this->calendarEvent->id,
            'name' => 'John Doe',
        ]);

        $participant2 = \App\Models\Participant::create([
            'calendar_event_id' => $this->calendarEvent->id,
            'name' => 'Jane Smith',
        ]);

        // Создаем записи транскрипта
        TranscriptEntry::create([
            'calendar_event_id' => $this->calendarEvent->id,
            'participant_id' => $participant1->id,
            'text' => 'Hello everyone',
            'start_relative' => 0.0,
            'end_relative' => 3.0,
            'start_absolute' => now(),
            'end_absolute' => now()->addSeconds(3),
        ]);

        TranscriptEntry::create([
            'calendar_event_id' => $this->calendarEvent->id,
            'participant_id' => $participant2->id,
            'text' => 'Hi John!',
            'start_relative' => 3.0,
            'end_relative' => 5.0,
            'start_absolute' => now()->addSeconds(3),
            'end_absolute' => now()->addSeconds(5),
        ]);

        // Мокаем OpenRouter клиент и проверяем формат транскрипта
        $mockClient = Mockery::mock(OpenRouterClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')
            ->once()
            ->withArgs(function ($messages) {
                // Проверяем, что транскрипт содержит правильный формат
                $transcriptMessage = $messages[2]->content ?? '';
                return str_contains($transcriptMessage, 'John Doe: Hello everyone')
                    && str_contains($transcriptMessage, 'Jane Smith: Hi John!');
            })
            ->andReturn('{"test": "data"}');
        });

        $this->app->instance(OpenRouterClient::class, $mockClient);

        // Вызываем сервис генерации
        $service = $this->app->make(FollowupService::class);
        $service->generate($this->calendarEvent, $this->team, $this->user);

        $this->addToAssertionCount(1);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
