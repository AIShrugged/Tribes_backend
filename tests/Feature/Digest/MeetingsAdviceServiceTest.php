<?php

namespace Tests\Feature\Digest;

use App\Enums\AgendaStatus;
use App\Models\CalendarEvent;
use App\Models\MeetingAgenda;
use App\Models\Organization;
use App\Models\Profile;
use App\Models\Source;
use App\Models\TaskDigest;
use App\Models\User;
use App\Services\Digest\MeetingsAdviceService;
use App\Services\OpenRouterClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingsAdviceServiceTest extends TestCase
{
    use RefreshDatabase;

    private function mockLLM(array $response): void
    {
        $mock = Mockery::mock(OpenRouterClient::class);
        $mock->shouldReceive('chat')->andReturn(json_encode($response, JSON_UNESCAPED_UNICODE));
        $this->app->instance(OpenRouterClient::class, $mock);
    }

    private function setupUserOrgMeeting(string $title = 'Meeting', array $agendaJson = null): array
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org']);
        $user = User::factory()->create();
        $org->users()->attach($user->id, ['role' => 'employee']);

        $source = Source::create([
            'user_id' => $user->id,
            'type' => 'google_calendar',
            'external_id' => 'src-' . $user->id,
            'identity' => $user->email,
        ]);

        $event = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'ev-' . uniqid(),
            'platform' => 'google_meet',
            'title' => $title,
            'url' => 'https://meet.google.com/x',
            'description' => '',
            'starts_at' => Carbon::now()->setHour(14)->setMinute(0),
            'ends_at' => Carbon::now()->setHour(15)->setMinute(0),
            'required_bot' => false,
        ]);

        if ($agendaJson !== null) {
            MeetingAgenda::create([
                'calendar_event_id' => $event->id,
                'type' => 'general',
                'status' => AgendaStatus::DONE->value,
                'user_id' => null,
                'raw_json' => $agendaJson,
            ]);
        }

        return ['user' => $user, 'org' => $org, 'event' => $event];
    }

    #[Test]
    public function returns_null_when_user_has_no_meetings(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org']);
        $user = User::factory()->create();
        $org->users()->attach($user->id, ['role' => 'employee']);

        $svc = app(MeetingsAdviceService::class);
        $result = $svc->generate($user, $org, Carbon::now()->startOfDay());

        $this->assertNull($result);
        $this->assertDatabaseCount('task_digests', 0);
    }

    #[Test]
    public function returns_null_when_meetings_have_no_agenda(): void
    {
        ['user' => $u, 'org' => $o, 'event' => $e] = $this->setupUserOrgMeeting('No agenda', null);

        $svc = app(MeetingsAdviceService::class);
        $result = $svc->generate($u, $o, Carbon::now()->startOfDay());

        $this->assertNull($result, 'no agenda → no advice');
    }

    #[Test]
    public function generates_advice_and_persists_to_new_digest_row(): void
    {
        ['user' => $u, 'org' => $o, 'event' => $e] = $this->setupUserOrgMeeting(
            'Sprint Planning',
            ['meeting_goal' => 'Plan sprint', 'discussion_topics' => [['title' => 'Backlog']], 'commitments_check' => []]
        );

        $this->mockLLM([
            'meetings_advice' => [
                ['meeting_id' => $e->id, 'tips' => ['Подготовь оценки задач', 'Проверь блокеры']],
            ],
        ]);

        $svc = app(MeetingsAdviceService::class);
        $result = $svc->generate($u, $o, Carbon::now()->startOfDay());

        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->assertSame($e->id, $result[0]['meeting_id']);
        $this->assertCount(2, $result[0]['tips']);

        $this->assertDatabaseCount('task_digests', 1);
        $digest = TaskDigest::first();
        $this->assertCount(1, $digest->content['meetings_advice']);
    }

    #[Test]
    public function merges_advice_into_existing_digest_row(): void
    {
        ['user' => $u, 'org' => $o, 'event' => $e] = $this->setupUserOrgMeeting(
            'Standup',
            ['meeting_goal' => 'Sync', 'discussion_topics' => [], 'commitments_check' => []]
        );

        // Pre-existing digest from 06:30
        TaskDigest::create([
            'user_id' => $u->id,
            'organization_id' => $o->id,
            'period_type' => 'daily',
            'period_start' => Carbon::now()->startOfDay()->toDateString(),
            'content' => [
                'kind' => 'daily',
                'progress' => ['something'],
                'problems' => [],
                'priorities' => [],
            ],
            'expires_at' => Carbon::now()->addDay(),
        ]);

        $this->mockLLM([
            'meetings_advice' => [
                ['meeting_id' => $e->id, 'tips' => ['Подготовь обновления']],
            ],
        ]);

        $svc = app(MeetingsAdviceService::class);
        $svc->generate($u, $o, Carbon::now()->startOfDay());

        $this->assertDatabaseCount('task_digests', 1);
        $digest = TaskDigest::first();
        // Existing fields preserved
        $this->assertSame(['something'], $digest->content['progress']);
        // New field merged
        $this->assertCount(1, $digest->content['meetings_advice']);
    }

    #[Test]
    public function ignores_hallucinated_meeting_ids(): void
    {
        ['user' => $u, 'org' => $o, 'event' => $e] = $this->setupUserOrgMeeting(
            'Real',
            ['meeting_goal' => 'Goal']
        );

        $this->mockLLM([
            'meetings_advice' => [
                ['meeting_id' => $e->id, 'tips' => ['real tip']],
                ['meeting_id' => 999999, 'tips' => ['hallucinated — should be dropped']],
            ],
        ]);

        $svc = app(MeetingsAdviceService::class);
        $result = $svc->generate($u, $o, Carbon::now()->startOfDay());

        $this->assertCount(1, $result, 'hallucinated meeting_id should be filtered out');
        $this->assertSame($e->id, $result[0]['meeting_id']);
    }

    #[Test]
    public function skips_users_not_in_org(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org']);
        $outsider = User::factory()->create();

        $svc = app(MeetingsAdviceService::class);
        $result = $svc->generate($outsider, $org, Carbon::now()->startOfDay());

        $this->assertNull($result);
        $this->assertDatabaseCount('task_digests', 0);
    }

    #[Test]
    public function filters_meetings_to_organization_owned(): void
    {
        // User is in org A, but meeting is owned by user from org B.
        $orgA = Organization::create(['name' => 'OrgA', 'slug' => 'orga']);
        $orgB = Organization::create(['name' => 'OrgB', 'slug' => 'orgb']);

        $userInA = User::factory()->create();
        $orgA->users()->attach($userInA->id, ['role' => 'employee']);

        $userInB = User::factory()->create();
        $orgB->users()->attach($userInB->id, ['role' => 'employee']);

        // Meeting owned by orgB user
        $sourceB = Source::create([
            'user_id' => $userInB->id,
            'type' => 'google_calendar',
            'external_id' => 'src-b',
            'identity' => $userInB->email,
        ]);
        $eventB = CalendarEvent::create([
            'source_id' => $sourceB->id,
            'external_id' => 'ev-b',
            'platform' => 'google_meet',
            'title' => 'OrgB-owned meeting',
            'url' => 'https://meet.google.com/b',
            'description' => '',
            'starts_at' => Carbon::now()->setHour(14),
            'ends_at' => Carbon::now()->setHour(15),
            'required_bot' => false,
        ]);
        MeetingAgenda::create([
            'calendar_event_id' => $eventB->id,
            'type' => 'general',
            'status' => AgendaStatus::DONE->value,
            'user_id' => null,
            'raw_json' => ['meeting_goal' => 'goal'],
        ]);
        // Link userInA to the event (e.g. via profile invite)
        $profileA = Profile::create([
            'user_id' => $userInA->id,
            'channel_id' => 1,
            'channel_identifier' => 'pa',
            'name' => $userInA->name,
        ]);
        $eventB->profiles()->attach($profileA->id);

        // Asking for orgA digest: meeting owned by orgB should be EXCLUDED.
        $svc = app(MeetingsAdviceService::class);
        $result = $svc->generate($userInA, $orgA, Carbon::now()->startOfDay());

        $this->assertNull($result, 'OrgB-owned meeting must not appear in OrgA digest');
    }
}
