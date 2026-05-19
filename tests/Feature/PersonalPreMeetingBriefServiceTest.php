<?php

namespace Tests\Feature;

use App\Enums\AgendaStatus;
use App\Models\CalendarEvent;
use App\Models\MeetingAgenda;
use App\Models\Organization;
use App\Models\Profile;
use App\Models\Source;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PersonalPreMeetingBriefServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('features.enable_personal_premeeting_brief', true);
    }

    private function createUserInOrg(Organization $org, string $tgId): User
    {
        $user = User::factory()->create();
        $org->users()->attach($user->id, ['role' => 'employee']);
        TelegramUser::create(['user_id' => $user->id, 'telegram_user_id' => $tgId]);
        return $user;
    }

    private function createEventWithSource(User $owner): CalendarEvent
    {
        $source = Source::create([
            'user_id' => $owner->id,
            'type' => 'google_calendar',
            'external_id' => 'src-'.$owner->id,
            'identity' => "{$owner->email}",
        ]);

        return CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'event-'.$owner->id,
            'platform' => 'google_meet',
            'title' => 'Sprint Sync',
            'url' => 'https://meet.google.com/x',
            'description' => '',
            'starts_at' => now()->addMinutes(15),
            'ends_at' => now()->addMinutes(45),
            'required_bot' => false,
        ]);
    }

    #[Test]
    public function skips_event_when_owner_org_cannot_be_determined(): void
    {
        // Event has no source.user, no sources pivot — H2 fail-closed scenario.
        // Per the security fix, we should NOT send to any participant.
        $org = Organization::create(['name' => 'Org', 'slug' => 'org']);
        $someUser = $this->createUserInOrg($org, '111');

        $event = CalendarEvent::create([
            'source_id' => null,
            'external_id' => 'orphan-event',
            'platform' => 'google_meet',
            'title' => 'Orphan',
            'url' => 'https://meet.google.com/x',
            'description' => '',
            'starts_at' => now()->addMinutes(15),
            'ends_at' => now()->addMinutes(45),
            'required_bot' => false,
        ]);

        $profile = Profile::create([
            'user_id' => $someUser->id,
            'channel_id' => 1,
            'channel_identifier' => "id-{$someUser->id}",
            'name' => $someUser->name,
        ]);
        $event->profiles()->attach($profile->id);

        $service = app(\App\Services\PersonalPreMeetingBriefService::class);
        $sent = $service->sendBriefs('99999');

        $this->assertSame(0, $sent, 'fail-closed: no owner org → no send');
    }

    #[Test]
    public function feature_flag_off_returns_zero(): void
    {
        Config::set('features.enable_personal_premeeting_brief', false);

        $service = app(\App\Services\PersonalPreMeetingBriefService::class);
        $sent = $service->sendBriefs();

        $this->assertSame(0, $sent);
    }

    #[Test]
    public function it_sends_to_internal_participants_only(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org']);
        $owner = $this->createUserInOrg($org, '111');
        $internal = $this->createUserInOrg($org, '222');

        // External user — has TelegramUser but not in this org
        $external = User::factory()->create();
        TelegramUser::create(['user_id' => $external->id, 'telegram_user_id' => '333']);

        $event = $this->createEventWithSource($owner);

        foreach ([$internal, $external] as $u) {
            $profile = Profile::create([
                'user_id' => $u->id,
                'channel_id' => 1,
                'channel_identifier' => "id-{$u->id}",
                'name' => $u->name,
            ]);
            $event->profiles()->attach($profile->id);
        }

        $service = app(\App\Services\PersonalPreMeetingBriefService::class);
        $sent = $service->sendBriefs('99999'); // test-user → all to safe ID

        // Only internal should have been counted (1)
        $this->assertSame(1, $sent);

        // Cache idempotency: internal got the brief, external did NOT
        $this->assertTrue(Cache::has("personal_pre_meeting_sent:{$event->id}:{$internal->id}"));
        $this->assertFalse(Cache::has("personal_pre_meeting_sent:{$event->id}:{$external->id}"));
    }

    #[Test]
    public function cache_prevents_duplicate_send(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org']);
        $owner = $this->createUserInOrg($org, '111');
        $internal = $this->createUserInOrg($org, '222');

        $event = $this->createEventWithSource($owner);
        $profile = Profile::create([
            'user_id' => $internal->id,
            'channel_id' => 1,
            'channel_identifier' => "id-{$internal->id}",
            'name' => $internal->name,
        ]);
        $event->profiles()->attach($profile->id);

        $service = app(\App\Services\PersonalPreMeetingBriefService::class);
        $first = $service->sendBriefs('99999');
        $second = $service->sendBriefs('99999');

        $this->assertSame(1, $first);
        $this->assertSame(0, $second, 'Second call should not duplicate');
    }

    #[Test]
    public function skips_users_without_telegram(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org']);
        $owner = $this->createUserInOrg($org, '111');

        // User without TG — attach to org but no TelegramUser row
        $noTg = User::factory()->create();
        $org->users()->attach($noTg->id, ['role' => 'employee']);

        $event = $this->createEventWithSource($owner);
        $profile = Profile::create([
            'user_id' => $noTg->id,
            'channel_id' => 1,
            'channel_identifier' => "id-{$noTg->id}",
            'name' => $noTg->name,
        ]);
        $event->profiles()->attach($profile->id);

        $service = app(\App\Services\PersonalPreMeetingBriefService::class);
        $sent = $service->sendBriefs('99999');

        $this->assertSame(0, $sent);
    }
}
