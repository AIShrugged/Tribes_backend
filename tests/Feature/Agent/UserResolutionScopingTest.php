<?php

namespace Tests\Feature\Agent;

use App\Models\Channel;
use App\Models\Organization;
use App\Models\Profile;
use App\Models\User;
use App\Services\Agent\Tools\GetUserInfoTool;
use App\Services\Agent\Tools\GetUserInsightsTool;
use App\Services\Agent\Tools\QueryTribesDataTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression for the live chat bug: asking to find "Иван" proposed a user from
 * ANOTHER organization (Дмитрий Иванов) and missed the same-org "Ivan" (Latin name).
 *
 * Two root causes, both fixed in QueryTribesDataTool::queryUsers:
 *  - when the chat has no explicit org, user search was global (cross-tenant leak);
 *  - name matching was script-literal (Cyrillic "Иван" never matched Latin "Ivan").
 */
class UserResolutionScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    #[Test]
    public function user_search_in_an_orgless_chat_stays_in_actor_orgs_and_matches_across_scripts(): void
    {
        // Actor's org with the intended assignee stored under a LATIN name.
        [$actor, $orgA] = $this->userInOrg('A');
        $ivan = User::factory()->create(['name' => 'Ivan']);
        $orgA->users()->attach($ivan->id, ['role' => 'employee']);

        // A DIFFERENT org the actor does NOT belong to, with a Cyrillic "…Иванов".
        [, $orgB] = $this->userInOrg('B');
        $dmitry = User::factory()->create(['name' => 'Дмитрий Иванов']);
        $orgB->users()->attach($dmitry->id, ['role' => 'employee']);

        // Chat with NO org (injected user, null org) — the reported scenario.
        $tool = new QueryTribesDataTool($actor, null, null, null);
        $result = $tool->execute(['entity' => 'users', 'filters' => ['name' => 'Иван']]);

        $this->assertTrue($result['success']);

        // Found the same-org Latin "Ivan" via transliteration.
        $ids = isset($result['user'])
            ? [$result['user']['id']]
            : collect($result['users'] ?? [])->pluck('id')->all();
        $this->assertContains($ivan->id, $ids, 'Same-org "Ivan" should be found from a Cyrillic query');

        // The cross-org "Дмитрий Иванов" must NEVER surface.
        $this->assertStringNotContainsString('Дмитрий', json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertNotContains($dmitry->id, $ids, 'Cross-tenant user must not be returned');
    }

    #[Test]
    public function get_user_info_does_not_leak_users_from_other_organizations(): void
    {
        [$actor, $orgA] = $this->userInOrg('A');
        [, $orgB] = $this->userInOrg('B');
        $foreign = User::factory()->create(['name' => 'Foreign Person', 'email' => 'foreign.person@x.test']);
        $orgB->users()->attach($foreign->id, ['role' => 'employee']);
        $this->actingAs($actor);

        $byEmail = (new GetUserInfoTool)->execute(['email' => 'foreign.person@x.test']);
        $this->assertFalse($byEmail['success'], 'must not resolve a foreign-org user by email');

        $byName = (new GetUserInfoTool)->execute(['name' => 'Foreign Person']);
        $this->assertFalse($byName['success'], 'must not resolve a foreign-org user by name');
        $this->assertStringNotContainsString('foreign.person@x.test', json_encode($byName, JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function get_user_info_hides_foreign_orgs_of_a_shared_org_teammate(): void
    {
        // Actor and teammate share org A; the teammate ALSO belongs to a foreign org B.
        [$actor, $orgA] = $this->userInOrg('A');
        $teammate = User::factory()->create(['name' => 'Shared Teammate', 'email' => 'mate@x.test']);
        $orgA->users()->attach($teammate->id, ['role' => 'employee']);

        $foreignOrg = Organization::create(['name' => 'Secret Foreign Org', 'slug' => 'secret-'.uniqid()]);
        $foreignOrg->users()->attach($teammate->id, ['role' => 'manager']);
        $this->actingAs($actor);

        $result = (new GetUserInfoTool)->execute(['name' => 'Shared Teammate']);

        $this->assertTrue($result['success']);
        // The teammate IS resolvable (shared org), but only the shared org may surface.
        $orgNames = collect($result['user']['organizations'])->pluck('name');
        $this->assertContains($orgA->name, $orgNames);
        $this->assertNotContains('Secret Foreign Org', $orgNames, 'must not reveal a teammate\'s foreign-org membership');
        $this->assertStringNotContainsString('Secret Foreign Org', json_encode($result, JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function insight_tools_block_a_profile_from_another_organization(): void
    {
        [$actor] = $this->userInOrg('A');
        [, $orgB] = $this->userInOrg('B');
        $foreign = User::factory()->create();
        $orgB->users()->attach($foreign->id, ['role' => 'employee']);
        $profile = Profile::create([
            'user_id' => $foreign->id,
            'channel_id' => Channel::query()->value('id'),
            'channel_identifier' => 'foreign.profile@x.test',
        ]);
        $this->actingAs($actor);

        // Psychological insights about a foreign-org person must be blocked on the chat path.
        $result = (new GetUserInsightsTool)->execute(['profile_id' => $profile->id]);

        $this->assertFalse($result['success']);
        $this->assertSame('Profile not accessible.', $result['error']);
    }

    /** @return array{0: User, 1: Organization} */
    private function userInOrg(string $suffix): array
    {
        $user = User::factory()->create();
        $org = Organization::create([
            'name' => "Org {$suffix}",
            'slug' => 'org-'.strtolower($suffix).'-'.uniqid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'manager']);

        return [$user, $org];
    }
}
