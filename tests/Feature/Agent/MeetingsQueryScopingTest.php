<?php

namespace Tests\Feature\Agent;

use App\Models\Organization;
use App\Models\User;
use App\Services\Agent\Tools\QueryTribesDataTool;
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
}
