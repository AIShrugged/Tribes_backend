<?php

namespace App\Services\Agent\Query;

use Illuminate\Database\Eloquent\Builder;

/**
 * A validated, tenant-scoped query ready to execute. The builder already has the
 * tenant scope and all filters applied; fields/relations/aggregate/limit describe
 * how StructuredQueryTool should run and shape the result.
 */
class CompiledQuery
{
    /**
     * @param  list<string>  $fields
     * @param  list<string>  $relations
     * @param  array<string, mixed>|null  $aggregate
     */
    public function __construct(
        public readonly Builder $builder,
        public readonly string $entity,
        public readonly array $fields,
        public readonly array $relations,
        public readonly ?array $aggregate,
        public readonly int $limit,
        public readonly int $offset = 0,
    ) {}
}
