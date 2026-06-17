<?php

namespace Tests\Feature;

use App\Models\CommitReport;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\User;
use App\Services\CommitReport\CommitReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CommitReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $org): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($org->id, ['role' => 'employee']); // @membership-allow direct attach (test)

        return $user;
    }

    private function report(Organization $org, array $opts = []): CommitReport
    {
        return app(CommitReportService::class)->save(array_merge([
            'repo' => 'AIShrugged/Tribes_backend', 'branch' => 'dev',
            'period_start' => '2026-06-15', 'period_end' => '2026-06-15', 'summary' => 'x',
            'items' => ['added' => [['sha' => str_pad('a', 40, '0'), 'title' => 'feat', 'summary' => 's']], 'fixed' => [], 'skipped' => []],
            'commit_count' => 1, 'total_in_window' => 1, 'status' => 'done',
            'organization_id' => $org->id,
        ], $opts));
    }

    #[Test]
    public function member_lists_org_reports_with_counts_and_items_count_header(): void
    {
        $org = Organization::create(['name' => 'O', 'slug' => 'ctl-1']);
        $this->report($org);
        Sanctum::actingAs($this->member($org));

        $res = $this->getJson("/api/v1/organizations/{$org->id}/commit-reports");

        $res->assertOk()
            ->assertHeader('Items-Count', '1')
            ->assertJsonStructure(['data' => [['id', 'repo', 'branch', 'status', 'added_count', 'fixed_count', 'created_at']]]);
        $this->assertSame(1, $res->json('data.0.added_count'));
        // list shape omits child arrays
        $this->assertArrayNotHasKey('added', $res->json('data.0'));
    }

    #[Test]
    public function non_member_cannot_access_and_existence_is_not_leaked(): void
    {
        // The org gate denies a non-member; bootstrap/app.php maps AccessDeniedHttpException
        // on api/* to 404 (no existence leak), so a non-member sees 404, not 403.
        $org = Organization::create(['name' => 'O', 'slug' => 'ctl-2']);
        $this->report($org);
        Sanctum::actingAs(User::factory()->create()); // not a member

        $this->getJson("/api/v1/organizations/{$org->id}/commit-reports")->assertNotFound();
    }

    #[Test]
    public function empty_reports_are_hidden_by_default_and_shown_with_include_empty(): void
    {
        $org = Organization::create(['name' => 'O', 'slug' => 'ctl-3']);
        $this->report($org, ['status' => 'empty', 'commit_count' => 0, 'items' => ['added' => [], 'fixed' => [], 'skipped' => []]]);
        Sanctum::actingAs($this->member($org));

        $this->getJson("/api/v1/organizations/{$org->id}/commit-reports")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/organizations/{$org->id}/commit-reports?include_empty=1")->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function show_returns_child_items_with_matched_task(): void
    {
        $org = Organization::create(['name' => 'O', 'slug' => 'ctl-4']);
        $issue = Issue::create(['name' => 'Task X', 'organization_id' => $org->id, 'status' => 'open']);
        $report = $this->report($org, [
            'items' => ['added' => [['sha' => str_pad('a', 40, '0'), 'title' => 'feat', 'summary' => 's', 'matched_issue_id' => $issue->id]], 'fixed' => [], 'skipped' => []],
        ]);
        Sanctum::actingAs($this->member($org));

        $res = $this->getJson("/api/v1/organizations/{$org->id}/commit-reports/{$report->id}");

        $res->assertOk()
            ->assertJsonPath('data.added.0.sha', str_pad('a', 40, '0'))
            ->assertJsonPath('data.added.0.short_sha', 'a000000')
            ->assertJsonPath('data.added.0.matched', true)
            ->assertJsonPath('data.added.0.matched_task.id', $issue->id)
            ->assertJsonPath('data.added.0.matched_task.name', 'Task X')
            ->assertJsonPath('data.added.0.matched_task.status', 'open');
    }

    #[Test]
    public function show_marks_an_unmatched_item_as_unmatched_with_null_task(): void
    {
        $org = Organization::create(['name' => 'O', 'slug' => 'ctl-4b']);
        $report = $this->report($org); // default added item carries no match
        Sanctum::actingAs($this->member($org));

        $this->getJson("/api/v1/organizations/{$org->id}/commit-reports/{$report->id}")
            ->assertOk()
            ->assertJsonPath('data.added.0.matched', false)
            ->assertJsonPath('data.added.0.matched_task', null);
    }

    #[Test]
    public function show_returns_404_for_a_foreign_org_report(): void
    {
        $org = Organization::create(['name' => 'O', 'slug' => 'ctl-5']);
        $other = Organization::create(['name' => 'X', 'slug' => 'ctl-5x']);
        $foreignReport = $this->report($other);
        Sanctum::actingAs($this->member($org));

        $this->getJson("/api/v1/organizations/{$org->id}/commit-reports/{$foreignReport->id}")->assertNotFound();
    }

    #[Test]
    public function index_exposes_matched_and_reviewed_run_health_counts(): void
    {
        $org = Organization::create(['name' => 'O', 'slug' => 'ctl-6']);
        $issue = Issue::create(['name' => 'T', 'organization_id' => $org->id, 'status' => 'open']);
        $report = $this->report($org, [
            'items' => ['added' => [
                ['sha' => str_pad('a', 40, '0'), 'title' => 'a', 'summary' => 's', 'matched_issue_id' => $issue->id],
                ['sha' => str_pad('b', 40, '0'), 'title' => 'b', 'summary' => 's'], // unmatched
            ], 'fixed' => [], 'skipped' => []],
        ]);
        \App\Models\CommitReportItem::where('commit_report_id', $report->id)
            ->where('sha', str_pad('a', 40, '0'))
            ->update(['review_status' => 'done']);

        Sanctum::actingAs($this->member($org));

        $this->getJson("/api/v1/organizations/{$org->id}/commit-reports")
            ->assertOk()
            ->assertJsonPath('data.0.added_count', 2)
            ->assertJsonPath('data.0.matched_count', 1)
            ->assertJsonPath('data.0.reviewed_count', 1);
    }

    #[Test]
    public function a_reviewed_item_dropped_on_resave_is_tombstoned_and_excluded_from_reads(): void
    {
        $org = Organization::create(['name' => 'O', 'slug' => 'ctl-7']);
        $issue = Issue::create(['name' => 'T', 'organization_id' => $org->id, 'status' => 'open']);
        $svc = app(CommitReportService::class);
        $shaX = str_pad('a', 40, '0');
        $shaY = str_pad('b', 40, '0');
        $base = [
            'repo' => 'AIShrugged/Tribes_backend', 'branch' => 'dev',
            'period_start' => '2026-06-15', 'period_end' => '2026-06-15', 'summary' => 'x',
            'commit_count' => 2, 'total_in_window' => 2, 'status' => 'done',
            'organization_id' => $org->id,
        ];

        // run 1: X (matched) + Y (unmatched)
        $report = $svc->save(array_merge($base, ['items' => ['added' => [
            ['sha' => $shaX, 'title' => 'x', 'summary' => 's', 'matched_issue_id' => $issue->id],
            ['sha' => $shaY, 'title' => 'y', 'summary' => 's'],
        ], 'fixed' => [], 'skipped' => []]]));

        // X gets a Pass-2 review
        \App\Models\CommitReportItem::where('commit_report_id', $report->id)
            ->where('sha', $shaX)->update(['review_status' => 'done']);

        // run 2 (same window): X is re-bucketed out of added/fixed, only Y remains
        $svc->save(array_merge($base, ['items' => ['added' => [
            ['sha' => $shaY, 'title' => 'y', 'summary' => 's'],
        ], 'fixed' => [], 'skipped' => [['sha' => $shaX]]]]));

        // the reviewed row is PRESERVED (audit) but TOMBSTONED
        $x = \App\Models\CommitReportItem::where('commit_report_id', $report->id)->where('sha', $shaX)->first();
        $this->assertNotNull($x);
        $this->assertNotNull($x->dropped_at, 'reviewed-but-dropped row must be tombstoned');
        $this->assertSame('done', $x->review_status);

        Sanctum::actingAs($this->member($org));

        // counts exclude the tombstoned orphan (only Y remains; its match/review are gone)
        $this->getJson("/api/v1/organizations/{$org->id}/commit-reports")
            ->assertOk()
            ->assertJsonPath('data.0.added_count', 1)
            ->assertJsonPath('data.0.matched_count', 0)
            ->assertJsonPath('data.0.reviewed_count', 0);

        // detail added[] excludes the orphan
        $shas = array_column(
            $this->getJson("/api/v1/organizations/{$org->id}/commit-reports/{$report->id}")
                ->assertOk()->json('data.added'),
            'sha',
        );
        $this->assertContains($shaY, $shas);
        $this->assertNotContains($shaX, $shas);
    }
}
