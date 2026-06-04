<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TeamUserKickTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function user_cannot_kick_self_from_team(): void
    {
        $organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
        ]);
        $manager = User::factory()->create();

        app(OrganizationMembershipService::class)->add(
            $organization,
            $manager,
            UserRole::MANAGER,
        );

        $team = $organization->refresh()->defaultTeam;
        $teamUser = $team->teamUsers()
            ->where('user_id', $manager->id)
            ->firstOrFail();

        Sanctum::actingAs($manager);

        $response = $this->postJson(
            "/api/v1/teams/{$team->id}/users/{$teamUser->id}/kick",
        );

        $response->assertNotFound();
        $this->assertDatabaseHas('team_user', [
            'id' => $teamUser->id,
            'team_id' => $team->id,
            'user_id' => $manager->id,
        ]);
    }

    #[Test]
    public function organization_member_can_kick_another_member(): void
    {
        $organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
        ]);
        $manager = User::factory()->create();
        $employee = User::factory()->create();
        $membership = app(OrganizationMembershipService::class);

        $membership->add($organization, $manager, UserRole::MANAGER);
        $membership->add($organization, $employee, UserRole::EMPLOYEE);

        $team = $organization->refresh()->defaultTeam;
        $teamUser = $team->teamUsers()
            ->where('user_id', $employee->id)
            ->firstOrFail();

        Sanctum::actingAs($manager);

        $response = $this->postJson(
            "/api/v1/teams/{$team->id}/users/{$teamUser->id}/kick",
        );

        $response->assertOk();
        $this->assertDatabaseMissing('team_user', [
            'id' => $teamUser->id,
        ]);
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $manager->id,
        ]);
    }
}
