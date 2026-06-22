<?php

namespace Tests\Feature\Agent;

use App\Models\Issue;
use App\Models\Organization;
use App\Models\User;
use App\Services\Agent\Query\StructuredQueryCompiler;
use App\Services\Agent\Query\StructuredQueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StructuredQueryCompilerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3'); // Organization creation provisions a workspace on s3.
    }

    private function compiler(): StructuredQueryCompiler
    {
        return $this->app->make(StructuredQueryCompiler::class);
    }

    #[Test]
    public function it_scopes_results_to_the_acting_users_tenant(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        [$userB, $orgB] = $this->userInOrg('B');
        $issueA = $this->makeIssue($orgA, $userA, 'A task');
        $issueB = $this->makeIssue($orgB, $userB, 'B task');

        $compiled = $this->compiler()->compile('tasks', ['fields' => ['id', 'name']], $userA);
        $ids = $compiled->builder->pluck('id')->all();

        $this->assertContains($issueA->id, $ids);
        $this->assertNotContains($issueB->id, $ids, 'Cross-tenant leak: org A must not see org B');
    }

    #[Test]
    public function it_rejects_the_assignee_name_trap(): void
    {
        [$userA] = $this->userInOrg('A');

        try {
            $this->compiler()->compile('tasks', [
                'filters' => [['field' => 'assignee_name', 'op' => '=', 'value' => 'Боб']],
            ], $userA);
            $this->fail('Expected StructuredQueryException for the assignee_name trap');
        } catch (StructuredQueryException $e) {
            $this->assertStringContainsString('assignee_name', implode(' ', $e->errors));
        }
    }

    #[Test]
    public function it_rejects_unknown_fields(): void
    {
        [$userA] = $this->userInOrg('A');

        $this->expectException(StructuredQueryException::class);
        $this->compiler()->compile('tasks', ['fields' => ['id', 'totally_made_up']], $userA);
    }

    #[Test]
    public function it_resolves_me_to_the_acting_user_server_side(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        // $other need not be an org member — the issue is visible via org scope; we only
        // assert the "me" filter narrows to the acting user's assigned rows.
        $other = User::factory()->create();

        $mine = $this->makeIssue($orgA, $userA, 'mine');
        $theirs = $this->makeIssue($orgA, $other, 'theirs');

        $compiled = $this->compiler()->compile('tasks', [
            'filters' => [['field' => 'assignee', 'op' => '=', 'value' => 'me']],
        ], $userA);
        $ids = $compiled->builder->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    #[Test]
    public function it_supports_aggregate_count_grouped_by_status(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        $this->makeIssue($orgA, $userA, '1', 'open');
        $this->makeIssue($orgA, $userA, '2', 'open');
        $this->makeIssue($orgA, $userA, '3', 'done');

        $compiled = $this->compiler()->compile('tasks', [
            'aggregate' => ['function' => 'count', 'group_by' => 'status'],
        ], $userA);

        $counts = $compiled->builder
            ->select('status', DB::raw('count(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status')
            ->toArray();

        $this->assertSame(2, (int) $counts['open']);
        $this->assertSame(1, (int) $counts['done']);
    }

    #[Test]
    public function it_refuses_an_entity_without_a_declared_scope(): void
    {
        // Inject a scopeless entity: the gate must fail closed, not run unscoped.
        config()->set('agent_catalog.entities.scopeless', [
            'label' => 'Scopeless',
            'model' => Issue::class,
            'fields' => ['id' => ['type' => 'integer']],
        ]);

        [$userA] = $this->userInOrg('A');

        $this->expectException(StructuredQueryException::class);
        $this->compiler()->compile('scopeless', ['fields' => ['id']], $userA);
    }

    #[Test]
    public function it_binds_filter_values_instead_of_interpolating_them(): void
    {
        [$userA] = $this->userInOrg('A');

        $compiled = $this->compiler()->compile('tasks', [
            'filters' => [['field' => 'status', 'op' => '=', 'value' => 'open']],
        ], $userA);

        $this->assertStringContainsString('"status" = ?', $compiled->builder->toSql());
        $this->assertContains('open', $compiled->builder->getBindings());
    }

    #[Test]
    public function it_supports_comparison_operators_on_date_fields(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        $soon = Issue::create([
            'user_id' => $userA->id, 'organization_id' => $orgA->id, 'assignee_id' => $userA->id,
            'name' => 'soon', 'type' => Issue::TYPE_ORGANIZATION, 'status' => 'open', 'due_date' => '2026-07-01',
        ]);
        Issue::create([
            'user_id' => $userA->id, 'organization_id' => $orgA->id, 'assignee_id' => $userA->id,
            'name' => 'later', 'type' => Issue::TYPE_ORGANIZATION, 'status' => 'open', 'due_date' => '2026-09-01',
        ]);

        $compiled = $this->compiler()->compile('tasks', [
            'fields' => ['id', 'due_date'],
            'filters' => [['field' => 'due_date', 'op' => '<=', 'value' => '2026-07-15']],
        ], $userA);

        $ids = $compiled->builder->pluck('id')->all();
        $this->assertSame([$soon->id], $ids);
    }

    // --- helpers ---

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

    private function makeIssue(Organization $org, User $assignee, string $name, string $status = 'open'): Issue
    {
        return Issue::create([
            'user_id' => $assignee->id,
            'organization_id' => $org->id,
            'assignee_id' => $assignee->id,
            'name' => $name,
            'type' => Issue::TYPE_ORGANIZATION,
            'status' => $status,
        ]);
    }
}
