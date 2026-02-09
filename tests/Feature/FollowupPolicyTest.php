<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Followup;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Source;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FollowupPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $manager;
    protected User $otherUser;
    protected Organization $organization;
    protected Team $team;
    protected Followup $followup;

    protected function setUp(): void
    {
        parent::setUp();

        // Создаем организацию
        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
        ]);

        // Создаем пользователей
        $this->user = User::factory()->create();
        $this->manager = User::factory()->create();
        $this->otherUser = User::factory()->create();

        // Получаем или создаем дефолтную методологию
        $defaultMethodology = Methodology::where('is_default', true)->first();
        if (!$defaultMethodology) {
            $defaultMethodology = Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ]);
        }

        // Создаем команду
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'organization_id' => $this->organization->id,
            'methodology_id' => $defaultMethodology->id,
        ]);

        // Привязываем пользователей к организации
        $this->organization->users()->attach($this->user, ['role' => 'employee']);
        $this->organization->users()->attach($this->manager, ['role' => 'manager']);

        // Привязываем пользователя к команде
        $this->team->users()->attach($this->user);

        // Создаем source для календарных событий
        $source = Source::create([
            'user_id' => $this->user->id,
            'type' => 'google_calendar',
            'external_id' => 'test-source-id',
            'identity' => 'test@example.com',
        ]);

        // Создаем календарное событие
        $calendarEvent = CalendarEvent::create([
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

        // Создаем методологию
        $methodology = Methodology::create([
            'name' => 'Test Methodology',
            'text' => 'Test methodology text',
            'scheme' => '{}',
            'organization_id' => $this->organization->id,
        ]);

        // Создаем followup
        $this->followup = Followup::create([
            'calendar_event_id' => $calendarEvent->id,
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'methodology_id' => $methodology->id,
            'status' => 'done',
            'text' => '{"test": "data"}',
        ]);
    }

    /** @test */
    public function team_member_can_view_their_followups()
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson("/api/v1/teams/{$this->team->id}/followups");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'id',
                    'calendar_event',
                    'team_id',
                    'user',
                    'methodology_id',
                    'status',
                    'text',
                ]
            ]
        ]);
    }

    /** @test */
    public function team_member_can_view_specific_followup()
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson("/api/v1/followups/{$this->followup->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'id' => $this->followup->id,
                'team_id' => $this->team->id,
                'user' => [
                    'id' => $this->user->id,
                ]
            ]
        ]);
    }

    /** @test */
    public function organization_manager_can_view_team_followups()
    {
        Sanctum::actingAs($this->manager);

        $response = $this->getJson("/api/v1/teams/{$this->team->id}/followups");

        $response->assertStatus(200);
    }

    /** @test */
    public function organization_manager_can_view_specific_followup()
    {
        Sanctum::actingAs($this->manager);

        $response = $this->getJson("/api/v1/followups/{$this->followup->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'id' => $this->followup->id,
            ]
        ]);
    }

    /** @test */
    public function user_without_access_cannot_view_team_followups()
    {
        Sanctum::actingAs($this->otherUser);

        $response = $this->getJson("/api/v1/teams/{$this->team->id}/followups");

        $response->assertStatus(404); // 404 вместо 403 для безопасности
    }

    /** @test */
    public function user_without_access_cannot_view_specific_followup()
    {
        Sanctum::actingAs($this->otherUser);

        $response = $this->getJson("/api/v1/followups/{$this->followup->id}");

        $response->assertStatus(404); // Not found because owned scope filters it out
    }

    /** @test */
    public function team_member_only_sees_their_own_followups()
    {
        // Создаем другого пользователя в той же команде
        $anotherTeamMember = User::factory()->create();
        $this->organization->users()->attach($anotherTeamMember, ['role' => 'employee']);
        $this->team->users()->attach($anotherTeamMember);

        // Создаем followup для другого пользователя
        $otherFollowup = Followup::create([
            'calendar_event_id' => $this->followup->calendar_event_id,
            'team_id' => $this->team->id,
            'user_id' => $anotherTeamMember->id,
            'methodology_id' => $this->followup->methodology_id,
            'status' => 'done',
            'text' => '{"test": "other data"}',
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson("/api/v1/teams/{$this->team->id}/followups");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Пользователь видит только свой followup
        $this->assertCount(1, $data);
        $this->assertEquals($this->followup->id, $data[0]['id']);
        $this->assertNotEquals($otherFollowup->id, $data[0]['id']);
    }

    /** @test */
    public function manager_sees_all_team_followups()
    {
        // Создаем другого пользователя в той же команде
        $anotherTeamMember = User::factory()->create();
        $this->organization->users()->attach($anotherTeamMember, ['role' => 'employee']);
        $this->team->users()->attach($anotherTeamMember);

        // Создаем followup для другого пользователя
        $otherFollowup = Followup::create([
            'calendar_event_id' => $this->followup->calendar_event_id,
            'team_id' => $this->team->id,
            'user_id' => $anotherTeamMember->id,
            'methodology_id' => $this->followup->methodology_id,
            'status' => 'done',
            'text' => '{"test": "other data"}',
        ]);

        Sanctum::actingAs($this->manager);

        $response = $this->getJson("/api/v1/teams/{$this->team->id}/followups");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Менеджер видит все followup'ы команды
        $this->assertCount(2, $data);
    }

    /** @test */
    public function unauthenticated_user_cannot_access_followups()
    {
        $response = $this->getJson("/api/v1/teams/{$this->team->id}/followups");
        $response->assertStatus(401);

        $response = $this->getJson("/api/v1/followups/{$this->followup->id}");
        $response->assertStatus(401);
    }
}
