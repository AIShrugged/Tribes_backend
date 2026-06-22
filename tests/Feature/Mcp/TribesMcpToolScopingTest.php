<?php

namespace Tests\Feature\Mcp;

use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Profile;
use App\Models\TranscriptUpload;
use App\Models\User;
use App\Services\Agent\Tools\GetFollowupTool;
use App\Services\Agent\Tools\GetOpenIssuesTool;
use App\Services\Agent\Tools\GetOrganizationContextTool;
use App\Services\Agent\Tools\GetTranscriptTool;
use App\Services\Agent\Tools\QueryTribesDataTool;
use App\Services\Agent\Tools\UpdateTaskStatusTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Proves the MCP-exposed tools scope every read/write to the acting user's
 * organization. Before the InteractsWithMcpTenant fix these assertions fail
 * (cross-org leak / unscoped mutation); after it they pass.
 */
class TribesMcpToolScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Creating an Organization provisions a shared workspace on the 's3' disk.
        Storage::fake('s3');
    }

    #[Test]
    public function get_open_issues_is_scoped_to_the_acting_users_organization(): void
    {
        [$userA, $orgA] = $this->serviceUserFor('A');
        [$userB, $orgB] = $this->serviceUserFor('B');

        $issueA = $this->makeIssue($orgA, $userA, 'A task');
        $issueB = $this->makeIssue($orgB, $userB, 'B task');

        $this->actingAs($userA);
        $result = (new GetOpenIssuesTool())->execute([]);

        $this->assertTrue($result['success']);
        $ids = collect($result['issues'] ?? [])->pluck('id')->all();
        $this->assertContains($issueA->id, $ids);
        $this->assertNotContains($issueB->id, $ids);
    }

    #[Test]
    public function update_task_status_cannot_touch_another_organizations_issue(): void
    {
        [$userA] = $this->serviceUserFor('A');
        [$userB, $orgB] = $this->serviceUserFor('B');
        $issueB = $this->makeIssue($orgB, $userB, 'B task');

        $this->actingAs($userA);
        $result = (new UpdateTaskStatusTool())->execute([
            'task_id' => $issueB->id,
            'status' => 'done',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('open', $issueB->fresh()->status);
    }

    #[Test]
    public function update_task_status_succeeds_for_own_organization(): void
    {
        [$userA, $orgA] = $this->serviceUserFor('A');
        $issueA = $this->makeIssue($orgA, $userA, 'A task');

        $this->actingAs($userA);
        $result = (new UpdateTaskStatusTool())->execute([
            'task_id' => $issueA->id,
            'status' => 'done',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('done', $issueA->fresh()->status);
    }

    #[Test]
    public function meeting_reads_reject_another_organizations_event(): void
    {
        [$userA] = $this->serviceUserFor('A');
        [$userB, $orgB] = $this->serviceUserFor('B');
        $eventB = $this->makeMeeting($orgB, $userB);

        $this->actingAs($userA);

        $transcript = (new GetTranscriptTool())->execute([
            'calendar_event_id' => $eventB->id,
            'user_confirmed' => true,
        ]);
        $this->assertFalse($transcript['success']);

        $followup = (new GetFollowupTool())->execute([
            'calendar_event_id' => $eventB->id,
        ]);
        $this->assertFalse($followup['success']);
    }

    #[Test]
    public function brain_issue_token_provisions_manager_membership_and_mcp_ability(): void
    {
        $org = Organization::create(['name' => 'Brain Org', 'slug' => 'brain-org-'.uniqid()]);

        $this->artisan('brain:issue-token', ['organization' => $org->id])->assertSuccessful();

        $user = User::where('email', "second-brain+org{$org->id}@brain.tribesmcp.local")->first();
        $this->assertNotNull($user);

        $membership = $org->users()->where('users.id', $user->id)->first();
        $this->assertNotNull($membership);
        $this->assertSame('manager', $membership->pivot->role);

        $token = $user->tokens()->where('name', 'second-brain')->first();
        $this->assertNotNull($token);
        $this->assertEqualsCanonicalizing(['mcp'], $token->abilities);
    }

    #[Test]
    public function query_db_tasks_default_to_the_acting_users_organization(): void
    {
        [$userA, $orgA] = $this->serviceUserFor('A');
        [$userB, $orgB] = $this->serviceUserFor('B');
        $issueA = $this->makeIssue($orgA, $userA, 'A task');
        $issueB = $this->makeIssue($orgB, $userB, 'B task');

        $this->actingAs($userA);
        $result = (new QueryTribesDataTool())->execute(['entity' => 'tasks']);

        $this->assertTrue($result['success']);
        $ids = collect($result['tasks'] ?? [])->pluck('id')->all();
        $this->assertContains($issueA->id, $ids);
        $this->assertNotContains($issueB->id, $ids);
    }

    #[Test]
    public function query_db_neutralizes_a_foreign_organization_filter_without_leaking(): void
    {
        [$userA] = $this->serviceUserFor('A');
        [$userB, $orgB] = $this->serviceUserFor('B');
        $issueB = $this->makeIssue($orgB, $userB, 'B task');

        $this->actingAs($userA);
        // A foreign organization_id is silently re-scoped to the caller's own org
        // (no error, but no cross-org leak either).
        $result = (new QueryTribesDataTool())->execute([
            'entity' => 'tasks',
            'filters' => ['organization_id' => $orgB->id],
        ]);

        $this->assertTrue($result['success']);
        $ids = collect($result['tasks'] ?? [])->pluck('id')->all();
        $this->assertNotContains($issueB->id, $ids);
    }

    #[Test]
    public function query_db_organization_links_are_scoped_to_the_acting_users_organization(): void
    {
        [$userA, $orgA] = $this->serviceUserFor('A');
        [$userB, $orgB] = $this->serviceUserFor('B');
        $linkA = \App\Models\OrganizationLink::create(['organization_id' => $orgA->id, 'url' => 'https://github.com/acme/a']);
        $linkB = \App\Models\OrganizationLink::create(['organization_id' => $orgB->id, 'url' => 'https://github.com/acme/b']);

        $this->actingAs($userA);

        // Default → only the acting user's org links.
        $own = (new QueryTribesDataTool())->execute(['entity' => 'organization_links']);
        $this->assertTrue($own['success']);
        $urls = collect($own['links'] ?? [])->pluck('url')->all();
        $this->assertContains($linkA->url, $urls);
        $this->assertNotContains($linkB->url, $urls);

        // A foreign organization_id is neutralized (no cross-org leak).
        $foreign = (new QueryTribesDataTool())->execute([
            'entity' => 'organization_links',
            'filters' => ['organization_id' => $orgB->id],
        ]);
        $this->assertTrue($foreign['success']);
        $this->assertNotContains($linkB->url, collect($foreign['links'] ?? [])->pluck('url')->all());
    }

    #[Test]
    public function query_db_user_insights_are_blocked_for_a_foreign_profile(): void
    {
        [$userA] = $this->serviceUserFor('A');
        [$userB] = $this->serviceUserFor('B');
        $profileB = $this->makeProfileFor($userB);

        $this->actingAs($userA);
        $result = (new QueryTribesDataTool())->execute([
            'entity' => 'user_insights',
            'filters' => ['profile_id' => $profileB->id],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Profile not accessible.', $result['error']);
    }

    #[Test]
    public function get_organization_context_defaults_to_own_org_and_rejects_others(): void
    {
        [$userA] = $this->serviceUserFor('A');
        [, $orgB] = $this->serviceUserFor('B');

        $this->actingAs($userA);

        // No org param → defaults to the acting user's org (no context seeded → success/null).
        $own = (new GetOrganizationContextTool())->execute([]);
        $this->assertTrue($own['success']);

        // Foreign org → denied.
        $foreign = (new GetOrganizationContextTool())->execute(['organization_id' => $orgB->id]);
        $this->assertFalse($foreign['success']);
    }

    private function makeProfileFor(User $user): Profile
    {
        return Profile::create([
            'user_id' => $user->id,
            'channel_id' => \App\Models\Channel::query()->value('id'),
            'channel_identifier' => $user->email,
        ]);
    }

    /** @return array{0: User, 1: Organization} */
    private function serviceUserFor(string $suffix): array
    {
        $user = User::factory()->create();
        $org = Organization::create([
            'name' => "Org {$suffix}",
            'slug' => 'org-'.strtolower($suffix).'-'.uniqid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'manager']);

        return [$user, $org];
    }

    private function makeIssue(Organization $org, User $user, string $name): Issue
    {
        return Issue::create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => $name,
            'type' => Issue::TYPE_ORGANIZATION,
            'status' => 'open',
        ]);
    }

    private function makeMeeting(Organization $org, User $user): CalendarEvent
    {
        $event = CalendarEvent::create([
            'title' => 'Meeting '.uniqid(),
            'platform' => 'test',
            'url' => 'https://meet.test/'.uniqid(),
            'description' => 'test meeting',
            'starts_at' => now()->subHour(),
            'ends_at' => now(),
        ]);

        TranscriptUpload::create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'calendar_event_id' => $event->id,
            'original_filename' => 'transcript.txt',
            'transcript_entries_count' => 1,
            'participants_count' => 1,
        ]);

        return $event;
    }
}
