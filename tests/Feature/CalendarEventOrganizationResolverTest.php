<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CalendarEvent;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Profile;
use App\Models\Source;
use App\Models\Team;
use App\Models\User;
use App\Services\CalendarEventOrganizationResolver;
use App\Services\OrganizationMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression for the highest-severity risk in the default-team rollout:
 * the default team contains every org member, so naive withCount('users')
 * winner-takes-all picking would route every event to it.
 */
class CalendarEventOrganizationResolverTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Methodology $methodology;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->methodology = Methodology::where('is_default', true)->first()
            ?? Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ]);

        $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->owner = User::factory()->create();
        app(OrganizationMembershipService::class)->add($this->org, $this->owner, UserRole::MANAGER);

        $this->source = Source::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'type' => 'manual_upload',
            'identity' => 'owner@acme.test',
            'external_id' => 'src_'.uniqid(),
            'auth_type' => 'none',
            'is_connected' => true,
        ]);
    }

    private function makeEventWithParticipants(array $participantUserIds): CalendarEvent
    {
        $event = CalendarEvent::create([
            'source_id' => $this->source->id,
            'creator_user_id' => $this->owner->id,
            'external_id' => 'evt_'.uniqid(),
            'platform' => 'google_meet',
            'title' => 'Standup',
            'description' => '',
            'url' => 'https://meet/'.uniqid(),
            'starts_at' => now(),
            'ends_at' => now()->addHour(),
        ]);

        $event->sources()->attach($this->source->id);

        foreach ($participantUserIds as $uid) {
            $profile = Profile::create([
                'user_id' => $uid,
                'channel_id' => 1,
                'channel_identifier' => "user_{$uid}@acme.test",
            ]);
            Participant::create([
                'calendar_event_id' => $event->id,
                'profile_id' => $profile->id,
                'name' => "User {$uid}",
            ]);
        }

        return $event;
    }

    #[Test]
    public function prefers_real_team_when_participants_match_real_team(): void
    {
        $member = User::factory()->create();
        app(OrganizationMembershipService::class)->add($this->org, $member, UserRole::EMPLOYEE);

        $realTeam = Team::create([
            'organization_id' => $this->org->id,
            'methodology_id' => $this->methodology->id,
            'name' => 'Backenders',
            'slug' => 'backenders',
        ]);
        $realTeam->users()->attach($member->id);

        $event = $this->makeEventWithParticipants([$member->id]);

        $result = app(CalendarEventOrganizationResolver::class)->resolve($event);

        $this->assertSame($realTeam->id, $result['team']->id, 'Should pick real team over default');
    }

    #[Test]
    public function falls_back_to_default_team_when_no_real_team_matches(): void
    {
        // Only owner is in org; no real teams exist. Default team is the only
        // candidate.
        $event = $this->makeEventWithParticipants([$this->owner->id]);

        $result = app(CalendarEventOrganizationResolver::class)->resolve($event);

        $this->assertNotNull($result);
        $this->assertTrue($result['team']->isDefault());
    }

    #[Test]
    public function prefers_real_team_when_no_participants_matched(): void
    {
        // Owner has both a real team and is in default. Without participants
        // matching, resolver should pick owner's real team, not default.
        $realTeam = Team::create([
            'organization_id' => $this->org->id,
            'methodology_id' => $this->methodology->id,
            'name' => 'Realt',
            'slug' => 'realt',
        ]);
        $realTeam->users()->attach($this->owner->id);

        $event = $this->makeEventWithParticipants([]);

        $result = app(CalendarEventOrganizationResolver::class)->resolve($event);

        $this->assertSame($realTeam->id, $result['team']->id);
    }
}
