<?php

namespace Tests\Feature\Agent;

use App\Services\Agent\Catalog\CatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Drift watchdog: the hand-written catalog overlay must stay in sync with the real
 * schema. If a migration renames/removes a column the catalog references, or an
 * entity loses its tenant scope, this fails — preventing silent data exposure /
 * broken queries.
 */
class CatalogConsistencyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_catalog_entity_matches_the_real_schema_and_declares_a_scope(): void
    {
        $catalog = new CatalogService;

        $this->assertNotEmpty($catalog->entityKeys(), 'Catalog must define at least one entity');

        foreach ($catalog->entityKeys() as $key) {
            $entity = $catalog->entity($key);

            /** @var class-string<Model> $modelClass */
            $modelClass = $entity['model'] ?? null;
            $this->assertNotNull($modelClass, "Entity '{$key}' must declare a model");

            $model = new $modelClass;
            $table = $model->getTable();
            $columns = collect(Schema::getColumns($table))->pluck('name')->all();

            // Every queryable field is a real column.
            foreach (array_keys($entity['fields'] ?? []) as $field) {
                $this->assertContains($field, $columns, "Catalog field '{$key}.{$field}' is not a real column in '{$table}'");
            }

            // Every relation's FK is a real column.
            foreach (($entity['relations'] ?? []) as $relation => $meta) {
                $this->assertContains($meta['fk'], $columns, "Relation FK '{$key}.{$relation}' ({$meta['fk']}) missing from '{$table}'");
            }

            // Every declared trap is a real column (it IS a real column we warn against).
            foreach (array_keys($entity['traps'] ?? []) as $trap) {
                $this->assertContains($trap, $columns, "Trap column '{$key}.{$trap}' missing from '{$table}'");
            }

            // Tenant scope must exist (fail-closed depends on it).
            $scope = $entity['scope'] ?? null;
            $this->assertNotNull($scope, "Entity '{$key}' must declare a tenant scope");
            $this->assertTrue(
                method_exists($model, 'scope'.ucfirst($scope)),
                "Scope 'scope".ucfirst((string) $scope)."' missing on {$modelClass}",
            );
        }
    }
}
