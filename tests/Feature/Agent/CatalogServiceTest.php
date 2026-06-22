<?php

namespace Tests\Feature\Agent;

use App\Enums\MeetingTaskStatus;
use App\Models\Issue;
use App\Services\Agent\Catalog\CatalogService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CatalogServiceTest extends TestCase
{
    private function catalog(): CatalogService
    {
        return new CatalogService;
    }

    #[Test]
    public function tasks_entity_resolves_model_scope_enum_and_relations(): void
    {
        $catalog = $this->catalog();

        $this->assertContains('tasks', $catalog->entityKeys());

        $entity = $catalog->entity('tasks');
        $this->assertSame(Issue::class, $entity['model']);
        $this->assertSame('visibleTo', $catalog->scopeMethod('tasks'));

        // Enum class-string is resolved to concrete values (derive-don't-duplicate).
        $expected = array_map(fn ($c) => $c->value, MeetingTaskStatus::cases());
        $this->assertEqualsCanonicalizing($expected, $entity['fields']['status']['enum']);

        // Relation maps to the real FK column.
        $this->assertSame('assignee_id', $catalog->relation('tasks', 'assignee')['fk']);
        $this->assertSame('team_id', $catalog->relation('tasks', 'team')['fk']);
    }

    #[Test]
    public function trap_columns_are_flagged_and_normal_fields_are_not(): void
    {
        $catalog = $this->catalog();

        $this->assertNotNull($catalog->trap('tasks', 'assignee_name'));
        $this->assertNull($catalog->trap('tasks', 'status'));
    }

    #[Test]
    public function describe_builds_an_llm_menu_without_raw_fk_columns(): void
    {
        $menu = $this->catalog()->describe('tasks');

        $this->assertSame('tasks', $menu['entity']);
        $this->assertSame('Задачи', $menu['label']);
        $this->assertArrayHasKey('name', $menu['fields']);
        $this->assertArrayHasKey('status', $menu['fields']);
        $this->assertArrayHasKey('assignee', $menu['relations']);
        $this->assertArrayHasKey('assignee_name', $menu['traps']);

        // Raw FK columns must NOT be exposed as queryable fields.
        $this->assertArrayNotHasKey('assignee_id', $menu['fields']);
        $this->assertArrayNotHasKey('team_id', $menu['fields']);
        $this->assertArrayNotHasKey('organization_id', $menu['fields']);
    }

    #[Test]
    public function unknown_entity_returns_null(): void
    {
        $this->assertNull($this->catalog()->entity('does_not_exist'));
        $this->assertNull($this->catalog()->describe('does_not_exist'));
    }
}
