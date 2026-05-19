<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\MeetingBriefDedup;
use App\Models\Organization;
use App\Models\Profile;
use App\Models\Source;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\PersonalPreMeetingBriefService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression: pre-meeting brief dedup survives cache flush.
 *
 * Original bug — `PreMeetingBriefService` and `PersonalPreMeetingBriefService` stored
 * "already sent" markers in Redis cache with TTL=1200s. On deploy churn the cache
 * was wiped (or a new Redis container replaced the previous one), causing the
 * brief to be re-sent in the next 10-min cron window. Users received the same
 * brief twice with a 10-minute gap.
 *
 * Fix: persistent dedup in `meeting_brief_dedup` table with UNIQUE constraint on
 * (calendar_event_id, brief_kind, recipient_id).
 */
class MeetingBriefDedupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('features.enable_personal_premeeting_brief', true);
    }

    #[Test]
    public function personal_brief_does_not_duplicate_when_cache_is_wiped(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org']);
        $owner = $this->createUserInOrg($org, '111');
        $internal = $this->createUserInOrg($org, '222');

        $event = $this->createEventWithSource($owner);
        $profile = Profile::create([
            'user_id' => $internal->id,
            'channel_id' => 1,
            'channel_identifier' => 'p-internal',
            'name' => $internal->name,
        ]);
        $event->profiles()->attach($profile->id);

        $service = app(PersonalPreMeetingBriefService::class);

        $first = $service->sendBriefs('99999');
        $this->assertSame(1, $first, 'first call must send');

        // Simulate Redis/cache being completely wiped between cron runs
        Cache::flush();

        $second = $service->sendBriefs('99999');
        $this->assertSame(0, $second, 'second call must NOT send — DB dedup survives cache wipe');

        // Verify exactly one dedup row exists
        $this->assertSame(1, MeetingBriefDedup::query()
            ->where('calendar_event_id', $event->id)
            ->where('brief_kind', MeetingBriefDedup::KIND_PERSONAL)
            ->where('recipient_id', $internal->id)
            ->count());
    }

    #[Test]
    public function preexisting_dedup_row_blocks_send(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org']);
        $owner = $this->createUserInOrg($org, '111');
        $internal = $this->createUserInOrg($org, '222');

        $event = $this->createEventWithSource($owner);
        $profile = Profile::create([
            'user_id' => $internal->id,
            'channel_id' => 1,
            'channel_identifier' => 'p-internal',
            'name' => $internal->name,
        ]);
        $event->profiles()->attach($profile->id);

        // Mark as already sent in DB (e.g. by an earlier scheduler run)
        MeetingBriefDedup::create([
            'calendar_event_id' => $event->id,
            'brief_kind' => MeetingBriefDedup::KIND_PERSONAL,
            'recipient_id' => $internal->id,
            'sent_at' => now()->subMinutes(15),
        ]);

        $service = app(PersonalPreMeetingBriefService::class);
        $sent = $service->sendBriefs('99999');

        $this->assertSame(0, $sent, 'pre-existing dedup row must prevent send');
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
            'identity' => $owner->email,
        ]);

        return CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'ev-'.$owner->id,
            'platform' => 'google_meet',
            'title' => 'Sync',
            'url' => 'https://meet.google.com/x',
            'description' => '',
            'starts_at' => now()->addMinutes(15),
            'ends_at' => now()->addMinutes(45),
            'required_bot' => false,
        ]);
    }
}
