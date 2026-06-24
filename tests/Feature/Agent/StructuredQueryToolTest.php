<?php

namespace Tests\Feature\Agent;

use App\Models\Issue;
use App\Models\Organization;
use App\Models\User;
use App\Services\Agent\Catalog\CatalogService;
use App\Services\Agent\Query\StructuredQueryCompiler;
use App\Services\Agent\Tools\DescribeEntityTool;
use App\Services\Agent\Tools\StructuredQueryTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StructuredQueryToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    private function tool(User $user): StructuredQueryTool
    {
        return new StructuredQueryTool(
            $user,
            $this->app->make(StructuredQueryCompiler::class),
            $this->app->make(CatalogService::class),
        );
    }

    private function toolWithLegacy(User $user, int $orgId): StructuredQueryTool
    {
        return new StructuredQueryTool(
            $user,
            $this->app->make(StructuredQueryCompiler::class),
            $this->app->make(CatalogService::class),
            new \App\Services\Agent\Tools\QueryTribesDataTool(
                $user,
                $this->app->make(\App\Services\AgentMemoryLookupService::class),
                $orgId,
                null,
            ),
        );
    }

    #[Test]
    public function it_returns_rows_scoped_to_the_acting_users_organization(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        [$userB, $orgB] = $this->userInOrg('B');
        $this->makeIssue($orgA, $userA, 'A task');
        $this->makeIssue($orgB, $userB, 'B task');

        $result = $this->tool($userA)->execute(['entity' => 'tasks', 'fields' => ['id', 'name', 'status']]);

        $this->assertTrue($result['success']);
        $names = collect($result['rows'])->pluck('name')->all();
        $this->assertContains('A task', $names);
        $this->assertNotContains('B task', $names);
    }

    #[Test]
    public function it_loads_relations_and_returns_their_labels(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        $this->makeIssue($orgA, $userA, 'A task');

        $result = $this->tool($userA)->execute([
            'entity' => 'tasks',
            'fields' => ['id', 'name'],
            'relations' => ['assignee'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('→assignee', $result['rows'][0]);
    }

    #[Test]
    public function it_returns_validation_errors_for_a_trap_field(): void
    {
        [$userA] = $this->userInOrg('A');

        $result = $this->tool($userA)->execute([
            'entity' => 'tasks',
            'filters' => [['field' => 'assignee_name', 'op' => '=', 'value' => 'Боб']],
        ]);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('assignee_name', implode(' ', $result['errors']));
    }

    #[Test]
    public function it_supports_count_aggregate(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        $this->makeIssue($orgA, $userA, '1', 'open');
        $this->makeIssue($orgA, $userA, '2', 'done');

        $result = $this->tool($userA)->execute([
            'entity' => 'tasks',
            'aggregate' => ['function' => 'count', 'group_by' => 'status'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('status', $result['group_by']);
        $this->assertArrayHasKey('open', $result['counts']);
    }

    #[Test]
    public function describe_entity_lists_entities_and_describes_one(): void
    {
        $describe = new DescribeEntityTool($this->app->make(CatalogService::class));

        $list = $describe->execute([]);
        $this->assertTrue($list['success']);
        $this->assertContains('tasks', collect($list['entities'])->pluck('entity')->all());

        $one = $describe->execute(['entity' => 'tasks']);
        $this->assertTrue($one['success']);
        $this->assertArrayHasKey('fields', $one);
        $this->assertArrayHasKey('assignee', $one['relations']);
        $this->assertArrayHasKey('assignee_name', $one['traps']);

        $unknown = $describe->execute(['entity' => 'nope']);
        $this->assertFalse($unknown['success']);
    }

    #[Test]
    public function it_paginates_with_offset_total_and_has_more(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        $this->makeIssue($orgA, $userA, 't1');
        $this->makeIssue($orgA, $userA, 't2');
        $this->makeIssue($orgA, $userA, 't3');

        $tool = $this->tool($userA);

        $page1 = $tool->execute(['entity' => 'tasks', 'fields' => ['id'], 'limit' => 2, 'offset' => 0]);
        $this->assertTrue($page1['success']);
        $this->assertSame(3, $page1['total']);
        $this->assertSame(2, $page1['count']);
        $this->assertTrue($page1['has_more']);
        $this->assertSame(2, $page1['next_offset']);

        $page2 = $tool->execute(['entity' => 'tasks', 'fields' => ['id'], 'limit' => 2, 'offset' => 2]);
        $this->assertSame(1, $page2['count']);
        $this->assertSame(3, $page2['total']);
        $this->assertFalse($page2['has_more']);
        $this->assertNull($page2['next_offset']);
    }

    #[Test]
    public function it_delegates_uncatalogued_entities_to_the_legacy_tool(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');

        // 'users' is not catalogued → delegated to QueryTribesDataTool. Structured filter
        // list is translated to the legacy field=>value map.
        $result = $this->toolWithLegacy($userA, $orgA->id)->execute([
            'entity' => 'users',
            'filters' => [['field' => 'user_id', 'value' => $userA->id]],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame($userA->id, $result['user']['id']);
    }

    #[Test]
    public function it_uses_the_compiler_for_catalogued_entities_even_with_a_fallback(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        $this->makeIssue($orgA, $userA, 'A task');

        $result = $this->toolWithLegacy($userA, $orgA->id)->execute([
            'entity' => 'tasks',
            'fields' => ['id', 'name'],
        ]);

        $this->assertTrue($result['success']);
        // Compiler path returns 'rows' (legacy queryTasks would return 'tasks').
        $this->assertArrayHasKey('rows', $result);
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
