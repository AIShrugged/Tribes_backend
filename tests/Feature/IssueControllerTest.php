<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IssueControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_lists_persons_and_manages_manual_issues_with_attachments(): void
    {
        config()->set('filesystems.issue_attachments_disk', 's3');
        Storage::fake('s3');

        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($owner, $assignee);

        $this->actingAs($owner)
            ->getJson('/api/v1/persons')
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $owner->id])
            ->assertJsonFragment(['id' => $assignee->id]);

        $createResponse = $this->actingAs($owner)
            ->postJson('/api/v1/issues', [
                'name' => 'Fix webhook race',
                'description' => 'Retry handling duplicates work.',
                'type' => 'backend',
                'organization_id' => $organization->id,
                'team_id' => $team->id,
                'assignee_id' => $assignee->id,
            ])->assertStatus(201)
            ->assertJsonPath('data.name', 'Fix webhook race')
            ->assertJsonPath('data.type', 'backend')
            ->assertJsonPath('data.organization_id', $organization->id)
            ->assertJsonPath('data.team_id', $team->id)
            ->assertJsonPath('data.assignee_id', $assignee->id)
            ->assertJsonPath('data.close_date', null);

        $issueId = $createResponse->json('data.id');

        $this->actingAs($owner)
            ->getJson('/api/v1/issues?type=backend&assignee='.$assignee->id.'&organization_id='.$organization->id.'&team_id='.$team->id)
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $issueId);

        $this->actingAs($owner)
            ->patchJson("/api/v1/issues/{$issueId}", [
                'status' => 'done',
            ])->assertStatus(200)
            ->assertJsonPath('data.status', 'done');

        $this->assertDatabaseHas('issues', [
            'id' => $issueId,
            'user_id' => $owner->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'assignee_id' => $assignee->id,
            'type' => 'backend',
            'status' => 'done',
        ]);

        $this->assertNotNull(Issue::findOrFail($issueId)->close_date);

        $uploadResponse = $this->actingAs($owner)
            ->post("/api/v1/issues/{$issueId}/attachments", [
                'file' => UploadedFile::fake()->create('error.log', 8, 'text/plain'),
            ])->assertStatus(201);

        $attachmentId = $uploadResponse->json('data.id');
        $path = $uploadResponse->json('data.file_path');
        $fileUrl = $uploadResponse->json('data.file_url');

        Storage::disk('s3')->assertExists($path);
        $this->assertNotNull($fileUrl);

        $this->actingAs($owner)
            ->getJson("/api/v1/issues/{$issueId}/attachments")
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $attachmentId)
            ->assertJsonPath('data.0.file_url', $fileUrl);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/attachments/{$attachmentId}")
            ->assertStatus(200);

        Storage::disk('s3')->assertMissing($path);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/issues/{$issueId}")
            ->assertStatus(200);

        $this->assertSoftDeleted('issues', [
            'id' => $issueId,
        ]);
    }

    #[Test]
    public function it_rejects_manual_issue_creation_for_foreign_team_scope(): void
    {
        $owner = User::factory()->create();
        [$organization] = $this->createTenantContextFor($owner);

        $methodology = Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ]);

        $otherOrganization = Organization::create([
            'name' => 'Other Org',
            'slug' => 'other-org',
        ]);

        $otherTeam = Team::create([
            'organization_id' => $otherOrganization->id,
            'methodology_id' => $methodology->id,
            'name' => 'Other Team',
            'slug' => 'other-team',
        ]);

        $this->actingAs($owner)
            ->postJson('/api/v1/issues', [
                'name' => 'Foreign issue',
            'type' => 'backend',
            'organization_id' => $organization->id,
            'team_id' => $otherTeam->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['team_id']);
    }

    #[Test]
    public function it_lists_owned_source_backed_issues_in_the_same_index(): void
    {
        $owner = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($owner);

        $issue = Issue::create([
            'user_id' => $owner->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'name' => 'Meeting follow-up',
            'description' => 'Created from meeting source.',
            'type' => 'organization',
            'status' => 'open',
            'sourceable_type' => 'App\Models\CalendarEvent',
            'sourceable_id' => 123,
        ]);

        $this->actingAs($owner)
            ->getJson('/api/v1/issues?organization_id='.$organization->id.'&team_id='.$team->id)
            ->assertStatus(200)
            ->assertJsonFragment([
                'id' => $issue->id,
                'name' => 'Meeting follow-up',
                'sourceable_type' => 'App\Models\CalendarEvent',
                'sourceable_id' => 123,
            ]);
    }

    #[Test]
    public function organization_manager_sees_organization_and_team_issues_while_team_employee_sees_only_team_issues(): void
    {
        $manager = User::factory()->create();
        $employee = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor([
            'users' => [$manager, $employee],
            'manager' => $manager,
        ]);

        $organizationIssue = Issue::create([
            'user_id' => $manager->id,
            'organization_id' => $organization->id,
            'team_id' => null,
            'name' => 'Org-level issue',
            'type' => 'organization',
            'status' => 'open',
        ]);

        $teamIssue = Issue::create([
            'user_id' => $manager->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'name' => 'Team-level issue',
            'type' => 'backend',
            'status' => 'open',
            'assignee_id' => $employee->id,
        ]);

        $this->actingAs($manager)
            ->getJson('/api/v1/issues?organization_id='.$organization->id)
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $organizationIssue->id])
            ->assertJsonFragment(['id' => $teamIssue->id]);

        $this->actingAs($employee)
            ->getJson('/api/v1/issues?organization_id='.$organization->id.'&team_id='.$team->id.'&assignee='.$employee->id)
            ->assertStatus(200)
            ->assertJsonMissing(['id' => $organizationIssue->id, 'name' => 'Org-level issue'])
            ->assertJsonFragment(['id' => $teamIssue->id, 'name' => 'Team-level issue']);
    }

    #[Test]
    public function employee_cannot_create_organization_level_issue_without_team_scope(): void
    {
        $employee = User::factory()->create();
        [$organization] = $this->createTenantContextFor($employee);

        $this->actingAs($employee)
            ->postJson('/api/v1/issues', [
                'name' => 'Org issue by employee',
                'type' => 'organization',
                'organization_id' => $organization->id,
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['organization_id']);
    }

    #[Test]
    public function issue_statuses_are_limited_to_open_in_progress_paused_and_done(): void
    {
        $manager = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor([
            'users' => [$manager],
            'manager' => $manager,
        ]);

        $this->actingAs($manager)
            ->postJson('/api/v1/issues', [
                'name' => 'Paused issue',
                'type' => 'organization',
                'status' => 'paused',
                'organization_id' => $organization->id,
                'team_id' => $team->id,
            ])->assertStatus(201)
            ->assertJsonPath('data.status', 'paused');

        $this->actingAs($manager)
            ->postJson('/api/v1/issues', [
                'name' => 'Cancelled issue',
                'type' => 'organization',
                'status' => 'cancelled',
                'organization_id' => $organization->id,
                'team_id' => $team->id,
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    private function createTenantContextFor(array|User $firstUser, User ...$users): array
    {
        $allUsers = [];
        $manager = null;

        if (is_array($firstUser)) {
            $allUsers = $firstUser['users'] ?? [];
            $manager = $firstUser['manager'] ?? null;
        } else {
            $allUsers = array_merge([$firstUser], $users);
        }

        $methodology = Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ]);

        $organization = Organization::create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $team = Team::create([
            'organization_id' => $organization->id,
            'methodology_id' => $methodology->id,
            'name' => 'Platform',
            'slug' => 'platform',
        ]);

        foreach ($allUsers as $user) {
            $organization->users()->attach($user->id, [
                'role' => $manager && $user->is($manager) ? 'manager' : 'employee',
            ]);
            $team->users()->attach($user->id);
        }

        return [$organization, $team];
    }
}
