<?php

namespace Tests\Feature;

use App\Enums\AgendaStatus;
use App\Models\CalendarEvent;
use App\Models\MeetingAgenda;
use App\Models\Organization;
use App\Models\Source;
use App\Models\Team;
use App\Models\User;
use App\Services\Agenda\AgendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgendaGenerationFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected bool $mockLlm = false;

    #[Test]
    public function agenda_generation_uses_fallback_model_when_primary_is_unavailable(): void
    {
        $modelsCalled = [];
        $fallbackModel = config('ai.providers.openrouter.fallback_models.0');

        Http::fake(function (Request $request) use (&$modelsCalled) {
            $model = $request->data()['model'] ?? null;
            $modelsCalled[] = $model;

            if ($model === 'google/gemini-3-pro-preview') {
                return Http::response([
                    'error' => [
                        'message' => 'No endpoints found for google/gemini-3-pro-preview.',
                        'code' => 404,
                    ],
                ], 404);
            }

            if ($model === 'anthropic/claude-3.5-sonnet') {
                return Http::response([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'previous_meeting_recap' => 'Mocked recap',
                                'topics_to_discuss' => ['Topic 1'],
                                'team_tasks_overview' => 'Mocked overview',
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                        'finish_reason' => 'stop',
                    ]],
                ], 200);
            }

            return Http::response([
                'error' => [
                    'message' => 'Unexpected model: '.$model,
                    'code' => 404,
                ],
            ], 404);
        });

        [$user, $event] = $this->makeEventWithTeam();

        $service = $this->app->make(AgendaService::class);
        $service->generateForEvent($event);

        $agenda = MeetingAgenda::where('calendar_event_id', $event->id)
            ->whereNull('user_id')
            ->where('type', 'general')
            ->firstOrFail();

        $this->assertSame(AgendaStatus::DONE, $agenda->status);
        $this->assertSame([
            'google/gemini-3-pro-preview',
            $fallbackModel,
        ], $modelsCalled);
        $this->assertNotEmpty($agenda->raw_json);
        $this->assertNotEmpty($agenda->content);
    }

    /**
     * @return array{0: User, 1: CalendarEvent}
     */
    private function makeEventWithTeam(): array
    {
        $organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
        ]);

        $user = User::factory()->create();
        $organization->users()->attach($user, ['role' => 'employee']);

        $team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'organization_id' => $organization->id,
        ]);
        $team->users()->attach($user);

        $source = Source::create([
            'user_id' => $user->id,
            'type' => 'google_calendar',
            'external_id' => 'test-source-id',
            'identity' => 'test@example.com',
        ]);

        $event = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'test-event-id',
            'platform' => 'google_meet',
            'title' => 'Test Meeting',
            'url' => 'https://meet.google.com/test',
            'description' => 'Test meeting description',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'required_bot' => false,
        ]);

        return [$user, $event];
    }
}
