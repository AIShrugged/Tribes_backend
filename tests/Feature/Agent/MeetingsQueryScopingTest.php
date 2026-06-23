<?php

namespace Tests\Feature\Agent;

use App\Models\Organization;
use App\Models\User;
use App\Services\Agent\Catalog\CatalogService;
use App\Services\Agent\Query\StructuredQueryCompiler;
use App\Services\Agent\Tools\QueryTribesDataTool;
use App\Services\Agent\Tools\StructuredQueryTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression: in an org-bound chat, asking for meetings raised
 * "column organization_id does not exist" — applyConversationScope injects
 * organization_id for meetings, and queryMeetings filtered calendar_events by a
 * column that does not exist (a meeting's org comes from its source).
 */
class MeetingsQueryScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    #[Test]
    public function org_scoped_meetings_query_does_not_hit_a_nonexistent_org_column(): void
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-'.uniqid()]);
        $org->users()->attach($user->id, ['role' => 'manager']);
        $this->actingAs($user);

        // Org-bound run: applyConversationScope injects organization_id for "meetings".
        $tool = new QueryTribesDataTool($user, null, $org->id, null);
        $result = $tool->execute(['entity' => 'meetings', 'limit' => 5]);

        $this->assertTrue(
            $result['success'],
            'org-scoped meetings query must not raise a SQL error: '.($result['error'] ?? '')
        );
    }

    #[Test]
    public function it_rejects_an_unsupported_meeting_filter_instead_of_silently_dropping_it(): void
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-'.uniqid()]);
        $org->users()->attach($user->id, ['role' => 'manager']);
        $this->actingAs($user);

        // "status" is not a meetings filter — previously dropped silently (→ wrong results).
        $result = (new QueryTribesDataTool($user, null, $org->id, null))
            ->execute(['entity' => 'meetings', 'filters' => ['status' => 'completed']]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unsupported', $result['error']);
        $this->assertStringContainsString('status', $result['error']);
    }

    #[Test]
    public function it_accepts_the_order_filter_for_latest_first(): void
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-'.uniqid()]);
        $org->users()->attach($user->id, ['role' => 'manager']);
        $this->actingAs($user);

        $result = (new QueryTribesDataTool($user, null, $org->id, null))
            ->execute(['entity' => 'meetings', 'filters' => ['order' => 'starts_at_desc'], 'limit' => 3]);

        $this->assertTrue($result['success'], $result['error'] ?? '');
    }

    #[Test]
    public function it_resolves_me_in_a_delegated_legacy_filter(): void
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-'.uniqid()]);
        $org->users()->attach($user->id, ['role' => 'manager']);
        $this->actingAs($user);

        $legacy = new QueryTribesDataTool($user, null, $org->id, null);
        $tool = new StructuredQueryTool($user, app(StructuredQueryCompiler::class), app(CatalogService::class), $legacy);

        // "meetings" is delegated to the legacy tool; "me" must become the actor id, not a
        // literal that lands in SQL (profile_id = me → SQLSTATE error before the fix).
        $result = $tool->execute(['entity' => 'meetings', 'filters' => [['field' => 'user_id', 'value' => 'me']], 'limit' => 3]);

        $this->assertTrue($result['success'], $result['error'] ?? '');
    }
}
