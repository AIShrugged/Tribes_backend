<?php

namespace App\Services\Agent\Query;

use App\Models\User;
use App\Services\Agent\Catalog\CatalogService;

/**
 * Turns a structured query {entity, fields, filters, relations, aggregate} into a
 * validated, tenant-scoped Eloquent builder.
 *
 * Guarantees:
 * - The agent NEVER writes raw SQL. Unknown/trap/forbidden fields are rejected.
 * - Tenant scope is injected by TenantScopeGate and cannot be skipped.
 * - All filter values are bound parameters — no string interpolation of LLM input.
 * - Joins are resolved from the catalog's declared relations, not from raw column names.
 * - Symbolic actor references ("me"/"self") are resolved to the acting user SERVER-SIDE.
 */
class StructuredQueryCompiler
{
    public const MAX_LIMIT = 50;

    public function __construct(
        private readonly CatalogService $catalog,
        private readonly TenantScopeGate $gate,
    ) {}

    /**
     * @param  array{fields?:list<string>,filters?:list<array<string,mixed>>,relations?:list<string>,aggregate?:array<string,mixed>,limit?:int}  $query
     *
     * @throws StructuredQueryException
     */
    public function compile(string $entity, array $query, User $actor): CompiledQuery
    {
        $descriptor = $this->catalog->entity($entity);

        if ($descriptor === null) {
            throw new StructuredQueryException([
                "Неизвестная сущность '{$entity}'. Доступные: ".implode(', ', $this->catalog->entityKeys()).'.',
            ]);
        }

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $modelClass */
        $modelClass = $descriptor['model'];
        $builder = $modelClass::query();

        // Tenant scope — injected here, cannot be skipped (fail-closed in the gate).
        $builder = $this->gate->apply($builder, $entity, $actor);

        $errors = [];

        $fields = $query['fields'] ?? ['id', 'name'];
        foreach ($fields as $field) {
            if ($trap = $this->catalog->trap($entity, $field)) {
                $errors[] = "Поле '{$field}': {$trap}";
            } elseif (! $this->catalog->hasField($entity, $field)) {
                $errors[] = "Неизвестное/недоступное поле '{$field}' у '{$entity}'.";
            }
        }

        foreach ($query['filters'] ?? [] as $filter) {
            $this->applyFilter($builder, $entity, (array) $filter, $actor, $errors);
        }

        $relations = [];
        foreach ($query['relations'] ?? [] as $relation) {
            if ($this->catalog->relation($entity, $relation) !== null) {
                $relations[] = $relation;
            } else {
                $errors[] = "Неизвестная связь '{$relation}' у '{$entity}'.";
            }
        }

        $aggregate = $query['aggregate'] ?? null;
        if (is_array($aggregate)) {
            $groupBy = $aggregate['group_by'] ?? null;
            if ($groupBy !== null && ! $this->catalog->hasField($entity, $groupBy)) {
                $errors[] = "Агрегация group_by по недоступному полю '{$groupBy}'.";
            }
        } else {
            $aggregate = null;
        }

        if ($errors !== []) {
            throw new StructuredQueryException($errors);
        }

        $limit = (int) ($query['limit'] ?? self::MAX_LIMIT);
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        return new CompiledQuery(
            builder: $builder,
            entity: $entity,
            fields: array_values($fields),
            relations: $relations,
            aggregate: $aggregate,
            limit: $limit,
            offset: $offset,
        );
    }

    /**
     * @param  array<string, mixed>  $filter
     * @param  list<string>  $errors
     */
    private function applyFilter(\Illuminate\Database\Eloquent\Builder $builder, string $entity, array $filter, User $actor, array &$errors): void
    {
        $field = $filter['field'] ?? null;
        $op = $this->normalizeOp((string) ($filter['op'] ?? '='));
        $value = $filter['value'] ?? null;

        if (! is_string($field) || $field === '') {
            $errors[] = 'Фильтр без поля.';

            return;
        }

        if ($trap = $this->catalog->trap($entity, $field)) {
            $errors[] = "Фильтр по '{$field}': {$trap}";

            return;
        }

        // Relation filter → resolve to the real FK column (joins/refs handled server-side).
        if ($relation = $this->catalog->relation($entity, $field)) {
            $id = $this->resolveSymbolic($value, $actor);

            if (! is_numeric($id)) {
                $errors[] = "Фильтр '{$field}' ожидает id пользователя/сущности (или \"me\"), получено: "
                    .json_encode($value, JSON_UNESCAPED_UNICODE);

                return;
            }

            $builder->where($relation['fk'], $op === 'in' ? '=' : $op, (int) $id);

            return;
        }

        if ($this->catalog->hasField($entity, $field)) {
            if ($op === 'in') {
                $builder->whereIn($field, (array) $value);
            } else {
                // Bound parameter — never interpolated into the SQL string.
                $builder->where($field, $op, $value);
            }

            return;
        }

        $errors[] = "Неизвестное поле в фильтре '{$field}'.";
    }

    private function resolveSymbolic(mixed $value, User $actor): mixed
    {
        if (is_array($value)) {
            $value = $value['user_ref'] ?? $value['id'] ?? null;
        }

        if (is_string($value) && in_array(mb_strtolower($value), ['me', 'self', 'current', 'current_user', 'я'], true)) {
            return $actor->id;
        }

        return $value;
    }

    private function normalizeOp(string $op): string
    {
        return match (mb_strtolower($op)) {
            '!=', 'ne', '<>' => '!=',
            '>', 'gt' => '>',
            '>=', 'gte' => '>=',
            '<', 'lt' => '<',
            '<=', 'lte' => '<=',
            'in' => 'in',
            default => '=',
        };
    }
}
