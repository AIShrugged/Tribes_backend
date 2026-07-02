<?php

namespace Tests\Feature;

use App\Services\Organization\ProjectCodeBackfiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exercises the one-time migration backfill with pre-existing (code-less) rows,
 * which RefreshDatabase's fresh-schema run never covers. Rows are inserted via the
 * query builder so the model observers do not assign codes up front.
 */
class ProjectCodeBackfillTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_backfills_org_codes_and_sequential_issue_numbers(): void
    {
        $devId = $this->insertOrganization('Dev_coding', 'dev-coding');
        $aucId = $this->insertOrganization('Auchan', 'auchan');

        $dev1 = $this->insertIssue(['organization_id' => $devId]);
        $auc1 = $this->insertIssue(['organization_id' => $aucId]);
        $dev2 = $this->insertIssue(['organization_id' => $devId]);

        app(ProjectCodeBackfiller::class)->run();

        $this->assertSame('DEV', $this->orgValue($devId, 'code'));
        $this->assertSame('AUC', $this->orgValue($aucId, 'code'));

        $this->assertSame('DEV-1', $this->issueValue($dev1, 'code'));
        $this->assertSame('DEV-2', $this->issueValue($dev2, 'code'));
        $this->assertSame('AUC-1', $this->issueValue($auc1, 'code'));

        $this->assertSame(2, (int) $this->orgValue($devId, 'last_issue_number'));
        $this->assertSame(1, (int) $this->orgValue($aucId, 'last_issue_number'));
    }

    #[Test]
    public function it_numbers_team_scoped_issues_under_the_team_organization(): void
    {
        $devId = $this->insertOrganization('Dev_coding', 'dev-coding');
        $teamId = $this->insertTeam($devId, 'dev-team');

        $issue = $this->insertIssue(['team_id' => $teamId]); // organization_id null

        app(ProjectCodeBackfiller::class)->run();

        $this->assertSame('DEV-1', $this->issueValue($issue, 'code'));
    }

    #[Test]
    public function it_is_idempotent_and_continues_existing_sequences(): void
    {
        $devId = $this->insertOrganization('Dev_coding', 'dev-coding');
        $first = $this->insertIssue(['organization_id' => $devId]);

        app(ProjectCodeBackfiller::class)->run();

        $second = $this->insertIssue(['organization_id' => $devId]); // added after first backfill
        app(ProjectCodeBackfiller::class)->run();

        $this->assertSame('DEV', $this->orgValue($devId, 'code'));
        $this->assertSame('DEV-1', $this->issueValue($first, 'code'));
        $this->assertSame('DEV-2', $this->issueValue($second, 'code'));
        $this->assertSame(2, (int) $this->orgValue($devId, 'last_issue_number'));
    }

    private function insertOrganization(string $name, string $slug): int
    {
        return DB::table('organizations')->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertTeam(int $organizationId, string $slug): int
    {
        $methodologyId = DB::table('methodologies')->insertGetId([
            'name' => 'M '.$slug,
            'text' => 'text',
            'scheme' => '{}',
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('teams')->insertGetId([
            'organization_id' => $organizationId,
            'methodology_id' => $methodologyId,
            'name' => 'Team '.$slug,
            'slug' => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertIssue(array $attributes): int
    {
        return DB::table('issues')->insertGetId(array_merge([
            'name' => 'Issue',
            'type' => 'development',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function orgValue(int $id, string $column): mixed
    {
        return DB::table('organizations')->where('id', $id)->value($column);
    }

    private function issueValue(int $id, string $column): mixed
    {
        return DB::table('issues')->where('id', $id)->value($column);
    }
}
