<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verifies the backfill migration produces the expected invariant when run
 * against pre-existing organizations + org_user rows that observer did not see.
 *
 * Pre-backfill state is built via raw DB::table inserts so model events
 * (including OrganizationObserver) do not fire — that's how the prod state
 * looks before the migration runs.
 */
class DefaultTeamBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function ensureDefaultMethodology(): int
    {
        $existing = DB::table('methodologies')->where('is_default', true)->value('id');
        if ($existing) {
            return $existing;
        }

        return DB::table('methodologies')->insertGetId([
            'name' => 'Default Methodology',
            'text' => 'Default methodology text',
            'scheme' => '{}',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedRawState(int $orgCount, int $usersPerOrg): array
    {
        $methodologyId = $this->ensureDefaultMethodology();

        $orgIds = [];
        $userIds = [];
        for ($i = 0; $i < $orgCount; $i++) {
            $orgIds[] = DB::table('organizations')->insertGetId([
                'name' => "Org {$i}",
                'slug' => "backfill-org-{$i}-".uniqid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($orgIds as $orgId) {
            for ($j = 0; $j < $usersPerOrg; $j++) {
                $uid = DB::table('users')->insertGetId([
                    'name' => 'User',
                    'email' => "u_{$orgId}_{$j}_".uniqid().'@test.local',
                    'password' => 'x',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $userIds[] = $uid;

                DB::table('organization_user')->insert([
                    'organization_id' => $orgId,
                    'user_id' => $uid,
                    'role' => 'employee',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return ['org_ids' => $orgIds, 'user_ids' => $userIds, 'methodology_id' => $methodologyId];
    }

    private function runBackfill(): void
    {
        // Re-execute the backfill body. Schema already exists (RefreshDatabase
        // ran the migration). We simulate the data-fill portion only.
        $methodologyId = DB::table('methodologies')->where('is_default', true)->value('id');
        $this->assertNotNull($methodologyId);

        DB::statement('
            INSERT INTO teams (organization_id, methodology_id, name, slug, is_default, created_at, updated_at)
            SELECT o.id, ?, ?, ?, true, NOW(), NOW()
            FROM organizations o
            WHERE NOT EXISTS (
                SELECT 1 FROM teams t WHERE t.organization_id = o.id AND t.is_default = true
            )
            AND NOT EXISTS (
                SELECT 1 FROM teams t WHERE t.organization_id = o.id AND t.slug = ?
            )
        ', [$methodologyId, 'General', 'general', 'general']);

        DB::statement('
            INSERT INTO team_user (team_id, user_id, created_at, updated_at)
            SELECT t.id, ou.user_id, NOW(), NOW()
            FROM teams t
            JOIN organization_user ou ON ou.organization_id = t.organization_id
            JOIN users u ON u.id = ou.user_id
            WHERE t.is_default = true
            ON CONFLICT (team_id, user_id) DO NOTHING
        ');
    }

    #[Test]
    public function backfill_creates_default_team_for_orphan_orgs(): void
    {
        $state = $this->seedRawState(orgCount: 3, usersPerOrg: 2);

        $this->runBackfill();

        foreach ($state['org_ids'] as $orgId) {
            $count = DB::table('teams')
                ->where('organization_id', $orgId)
                ->where('is_default', true)
                ->count();
            $this->assertSame(1, $count, "Org {$orgId} should have exactly 1 default team");
        }
    }

    #[Test]
    public function backfill_populates_team_user_for_all_org_members(): void
    {
        $state = $this->seedRawState(orgCount: 2, usersPerOrg: 3);

        $this->runBackfill();

        foreach ($state['org_ids'] as $orgId) {
            $expected = DB::table('organization_user')
                ->where('organization_id', $orgId)
                ->count();
            $actual = DB::table('team_user')
                ->join('teams', 'teams.id', '=', 'team_user.team_id')
                ->where('teams.organization_id', $orgId)
                ->where('teams.is_default', true)
                ->count();
            $this->assertSame($expected, $actual, "Org {$orgId} team_user count should match org_user count");
        }
    }

    #[Test]
    public function backfill_is_idempotent_on_rerun(): void
    {
        $state = $this->seedRawState(orgCount: 2, usersPerOrg: 2);

        $this->runBackfill();
        $teamUserCount = DB::table('team_user')->count();

        // Re-run — should be no-op via NOT EXISTS + ON CONFLICT.
        $this->runBackfill();
        $this->assertSame($teamUserCount, DB::table('team_user')->count());
    }
}
