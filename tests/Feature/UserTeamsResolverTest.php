<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\TeamNotificationSetting;
use App\Models\TelegramChatRegistration;
use App\Models\User;
use App\Services\OrganizationMembershipService;
use App\Services\UserTeamsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserTeamsResolverTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private Methodology $methodology;

    private UserTeamsResolver $resolver;

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
        $this->user = User::factory()->create();
        app(OrganizationMembershipService::class)->add($this->org, $this->user, UserRole::EMPLOYEE);
        $this->resolver = app(UserTeamsResolver::class);
    }

    private function makeRealTeam(string $name = 'Realt'): Team
    {
        return Team::create([
            'organization_id' => $this->org->id,
            'methodology_id' => $this->methodology->id,
            'name' => $name,
            'slug' => strtolower($name),
        ]);
    }

    #[Test]
    public function pipeline_trigger_returns_real_teams_when_user_has_real_teams(): void
    {
        $real = $this->makeRealTeam();
        $real->users()->attach($this->user->id);

        $teams = $this->resolver->forPipelineTrigger($this->user, $this->org->id);

        $this->assertCount(1, $teams);
        $this->assertSame($real->id, $teams->first()->id);
    }

    #[Test]
    public function pipeline_trigger_falls_back_to_default_when_no_real_teams_exist(): void
    {
        $teams = $this->resolver->forPipelineTrigger($this->user, $this->org->id);

        $this->assertCount(1, $teams);
        $this->assertTrue($teams->first()->isDefault());
    }

    #[Test]
    public function pipeline_trigger_returns_empty_when_user_has_no_teams_in_org(): void
    {
        $otherOrg = Organization::create(['name' => 'Other', 'slug' => 'other']);

        $teams = $this->resolver->forPipelineTrigger($this->user, $otherOrg->id);

        $this->assertCount(0, $teams);
    }

    #[Test]
    public function outbound_notification_filters_default_team_without_settings(): void
    {
        $teams = $this->resolver->forOutboundNotification($this->user, $this->org->id);

        $this->assertCount(0, $teams);
    }

    #[Test]
    public function outbound_notification_includes_default_team_when_it_has_settings(): void
    {
        $registration = TelegramChatRegistration::create([
            'telegram_chat_id' => 12345,
            'organization_id' => $this->org->id,
        ]);
        TeamNotificationSetting::create([
            'team_id' => $this->org->refresh()->defaultTeam->id,
            'event_type' => 'meeting_summary',
            'channel_type' => 'telegram',
            'notifiable_type' => TelegramChatRegistration::class,
            'notifiable_id' => $registration->id,
            'enabled' => true,
        ]);

        $teams = $this->resolver->forOutboundNotification($this->user, $this->org->id);

        $this->assertCount(1, $teams);
        $this->assertTrue($teams->first()->isDefault());
    }

    #[Test]
    public function outbound_notification_includes_real_teams_always(): void
    {
        $real = $this->makeRealTeam();
        $real->users()->attach($this->user->id);

        $teams = $this->resolver->forOutboundNotification($this->user, $this->org->id);

        // Real team always included (default still filtered, no settings on it).
        $this->assertCount(1, $teams);
        $this->assertFalse($teams->first()->isDefault());
        $this->assertSame($real->id, $teams->first()->id);
    }
}
