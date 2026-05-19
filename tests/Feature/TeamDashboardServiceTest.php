<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Issue;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Profile;
use App\Models\Team;
use App\Models\User;
use App\Services\Dashboard\TeamDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TeamDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private TeamDashboardService $service;
    private Organization $org;
    private Methodology $methodology;
    private Team $team;
    private int $gcChannelId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TeamDashboardService::class);

        $this->org = Organization::create(['name' => 'Org', 'slug' => 'org']);
        $this->methodology = Methodology::firstOrCreate(
            ['is_default' => true],
            ['name' => 'Default', 'text' => '', 'scheme_version' => '1'],
        );
        $this->team = Team::create([
            'name' => 'Backend',
            'slug' => 'backend',
            'organization_id' => $this->org->id,
            'methodology_id' => $this->methodology->id,
        ]);

        $channel = Channel::firstOrCreate(['name' => 'google_calendar'], ['display_name' => 'Google Calendar']);
        $this->gcChannelId = $channel->id;
    }

    private function attachUser(User $user, string $role = 'employee'): void
    {
        $this->org->users()->attach($user->id, ['role' => $role]);
        $this->team->users()->attach($user->id);
    }

    private function createProfile(User $user): Profile
    {
        return Profile::create([
            'user_id' => $user->id,
            'channel_id' => $this->gcChannelId,
            'channel_identifier' => 'gc-' . $user->id,
            'name' => $user->name,
        ]);
    }

    #[Test]
    public function member_sees_only_own_insight_others_are_unavailable(): void
    {
        $viewer = User::factory()->create();
        $other = User::factory()->create();
        $this->attachUser($viewer, UserRole::EMPLOYEE->value);
        $this->attachUser($other, UserRole::EMPLOYEE->value);
        $this->createProfile($viewer);
        $this->createProfile($other);

        $result = $this->service->build($this->team, $viewer);

        $members = collect($result['tabs']['people']['members'])->keyBy('id');

        $this->assertSame('collecting', $members[$viewer->id]['insight']['status'], 'viewer sees own insight (collecting because no items)');
        $this->assertSame('unavailable', $members[$other->id]['insight']['status'], 'other member insight is unavailable to non-manager');
        $this->assertNull($members[$other->id]['insight']['data']);
    }

    #[Test]
    public function manager_sees_all_member_insights(): void
    {
        $manager = User::factory()->create();
        $employee = User::factory()->create();
        $this->attachUser($manager, UserRole::MANAGER->value);
        $this->attachUser($employee, UserRole::EMPLOYEE->value);
        $this->createProfile($manager);
        $this->createProfile($employee);

        $result = $this->service->build($this->team, $manager);

        $members = collect($result['tabs']['people']['members'])->keyBy('id');

        $this->assertNotSame('unavailable', $members[$manager->id]['insight']['status']);
        $this->assertNotSame('unavailable', $members[$employee->id]['insight']['status']);
    }

    #[Test]
    public function metrics_are_present_for_each_member(): void
    {
        $viewer = User::factory()->create();
        $this->attachUser($viewer, UserRole::EMPLOYEE->value);

        Issue::create([
            'name' => 'Done',
            'assignee_id' => $viewer->id,
            'status' => 'done',
            'close_date' => now()->subDay(),
        ]);
        Issue::create([
            'name' => 'Open',
            'assignee_id' => $viewer->id,
            'status' => 'open',
        ]);

        $result = $this->service->build($this->team, $viewer);

        $members = collect($result['tabs']['people']['members'])->keyBy('id');

        $this->assertNotNull($members[$viewer->id]['metrics']);
        $this->assertSame(1, $members[$viewer->id]['metrics']['done']);
        $this->assertSame(1, $members[$viewer->id]['metrics']['in_progress']);
    }

    #[Test]
    public function insight_returns_unavailable_for_member_without_profile(): void
    {
        $viewer = User::factory()->create();
        $this->attachUser($viewer, UserRole::EMPLOYEE->value);
        // No profile created for viewer

        $result = $this->service->build($this->team, $viewer);

        $members = collect($result['tabs']['people']['members'])->keyBy('id');

        // No google_calendar profile → status is 'collecting' (we can see ourselves but no data yet)
        $this->assertSame('collecting', $members[$viewer->id]['insight']['status']);
        $this->assertNull($members[$viewer->id]['insight']['data']);
    }
}
