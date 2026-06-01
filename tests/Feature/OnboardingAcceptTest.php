<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationIssueType;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OnboardingAcceptTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function accept_can_run_multiple_times_and_updates_onboarded_at(): void
    {
        $manager = User::factory()->create();

        $organization = Organization::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'onboarded_at' => now()->subDay(),
        ]);
        $organization->users()->attach($manager->id, ['role' => 'manager']);

        OrganizationIssueType::firstOrCreate(
            ['organization_id' => null, 'key' => 'epic'],
            [
                'name' => 'Epic',
                'base_type' => 'epic',
                'is_active' => true,
            ],
        );

        Sanctum::actingAs($manager);

        $secondOnboardingTime = now()->startOfSecond();
        $this->travelTo($secondOnboardingTime);

        $this->postJson("/api/v1/organizations/{$organization->id}/accept-structure", [
            'organization' => [
                'name' => 'Acme Reboarded',
                'description' => 'Updated team context',
            ],
            'goals' => [
                [
                    'title' => 'Refresh onboarding',
                    'description' => 'Run onboarding again',
                    'tasks' => [],
                ],
            ],
            'team' => [],
        ])->assertOk();

        $organization->refresh();

        $this->assertSame('Acme Reboarded', $organization->name);
        $this->assertTrue($organization->onboarded_at->equalTo($secondOnboardingTime));
        $this->assertDatabaseHas('issues', [
            'organization_id' => $organization->id,
            'name' => 'Refresh onboarding',
            'type' => Issue::TYPE_EPIC,
        ]);
    }

    #[Test]
    public function accept_creates_missing_team_users_and_attaches_them_to_organization(): void
    {
        $manager = User::factory()->create();

        $organization = Organization::create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);
        $organization->users()->attach($manager->id, ['role' => 'manager']);

        OrganizationIssueType::firstOrCreate(
            ['organization_id' => null, 'key' => 'epic'],
            [
                'name' => 'Epic',
                'base_type' => 'epic',
                'is_active' => true,
            ],
        );

        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/organizations/{$organization->id}/accept-structure", [
            'organization' => [
                'name' => 'Acme Labs',
                'description' => 'Team context',
            ],
            'goals' => [
                [
                    'title' => 'Launch onboarding',
                    'description' => 'Prepare onboarding flow',
                    'tasks' => [
                        [
                            'title' => 'Prepare account setup',
                            'description' => 'Create initial accounts for the team',
                            'type' => 'organization',
                            'priority' => 0,
                        ],
                    ],
                ],
            ],
            'team' => [
                [
                    'name' => 'Jane Doe',
                    'email' => null,
                    'role' => 'employee',
                ],
                [
                    'name' => 'John Smith',
                    'email' => 'john@example.com',
                    'role' => 'manager',
                ],
            ],
        ])->assertOk();

        $fallbackUser = User::where('email', 'jane.doe@shrugged.ai')->first();
        $emailUser = User::where('email', 'john@example.com')->first();

        $this->assertNotNull($fallbackUser);
        $this->assertSame('Jane Doe', $fallbackUser->name);
        $this->assertNotSame('', $fallbackUser->password);
        $this->assertNotNull($fallbackUser->email_verified_at);
        $this->assertNotNull($emailUser);
        $this->assertSame('John Smith', $emailUser->name);
        $this->assertNotSame('', $emailUser->password);
        $this->assertNotNull($emailUser->email_verified_at);

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $fallbackUser->id,
            'role' => 'employee',
        ]);
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $emailUser->id,
            'role' => 'manager',
        ]);

        $teamMap = $organization->refresh()->team_map;

        $this->assertSame('jane.doe@shrugged.ai', $teamMap[0]['email']);
        $this->assertSame($fallbackUser->id, $teamMap[0]['system_user_id']);
        $this->assertTrue($teamMap[0]['already_in_system']);
        $this->assertSame('john@example.com', $teamMap[1]['email']);
        $this->assertSame($emailUser->id, $teamMap[1]['system_user_id']);
        $this->assertTrue($teamMap[1]['already_in_system']);

        $task = Issue::where('name', 'Prepare account setup')->first();

        $this->assertNotNull($task);
        $this->assertStringContainsString('## Context', $task->description);
        $this->assertStringContainsString('## Steps', $task->description);
        $this->assertStringContainsString('## Definition of done', $task->description);
    }
}
