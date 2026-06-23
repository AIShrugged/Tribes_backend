<?php

namespace App\Services\Agent\Tools;

use App\Models\User;
use App\Services\Agent\Catalog\CatalogService;
use App\Services\Agent\Query\StructuredQueryCompiler;
use App\Services\Agent\Query\StructuredQueryException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The single agent-facing READ tool.
 *
 * - Catalogued entities (see config/agent_catalog.php) are read via a validated,
 *   tenant-scoped STRUCTURED QUERY: joins resolved from declared relations, scope
 *   injected by TenantScopeGate, all values bound, traps rejected. No raw SQL.
 * - Entities not yet migrated to the catalog are delegated to the legacy
 *   QueryTribesDataTool (surface-cutover with fallback — zero regression). The
 *   agent always passes the same `filters: [{field, op, value}]` shape; for legacy
 *   entities it is translated to the legacy field=>value map.
 */
class StructuredQueryTool extends AbstractAgentTool
{
    public function __construct(
        private readonly User $user,
        private readonly StructuredQueryCompiler $compiler,
        private readonly CatalogService $catalog,
        private readonly ?QueryTribesDataTool $legacyFallback = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'query_data';
    }

    public function getDescription(): string
    {
        $catalogued = implode(', ', $this->catalog->entityKeys());
        $legacy = implode(', ', array_values(array_diff($this->legacyEntities(), $this->catalog->entityKeys())));

        return 'Прочитать данные через структурированную заявку (безопасно, со скоупом). НЕ пиши SQL. '
            ."Каталогизированные сущности (вызови describe_entity для полей/связей): {$catalogued}. "
            ."Прочие сущности (фильтры — простые пары field=value): {$legacy}.";
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entity' => [
                    'type' => 'string',
                    'enum' => $this->allEntities(),
                    'description' => 'Какую сущность читать. Для каталогизированных используй describe_entity.',
                ],
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Поля для возврата (каталогизированные сущности).',
                ],
                'filters' => [
                    'type' => 'array',
                    'items' => ['type' => 'object'],
                    'description' => 'Фильтры [{field, op, value}]. op: =, !=, >, >=, <, <=, in. '
                        .'Для связи (assignee/team) value = id или "me". Для не-каталогизированных сущностей op игнорируется (field=value).',
                ],
                'relations' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Связи для подгрузки (каталогизированные сущности).',
                ],
                'aggregate' => [
                    'type' => 'object',
                    'description' => 'Для подсчётов: {"function":"count","group_by":"<field>"}.',
                ],
                'limit' => ['type' => 'integer', 'description' => 'Максимум строк.'],
                'offset' => ['type' => 'integer', 'description' => 'Смещение (пагинация для не-каталогизированных сущностей).'],
            ],
            'required' => ['entity'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];
        $entity = $parameters['entity'] ?? null;

        if (! is_string($entity) || $entity === '') {
            return ['success' => false, 'error' => 'Параметр entity обязателен.', 'entities' => $this->allEntities()];
        }

        // Not catalogued yet → delegate to the legacy read tool (surface-cutover fallback).
        if ($this->catalog->entity($entity) === null) {
            return $this->delegateToLegacy($entity, $parameters);
        }

        try {
            $compiled = $this->compiler->compile($entity, $parameters, $this->user);
        } catch (StructuredQueryException $e) {
            return [
                'success' => false,
                'errors' => $e->errors,
                'hint' => "Вызови describe_entity({\"entity\":\"{$entity}\"}) для списка доступных полей и связей.",
            ];
        }

        if ($compiled->aggregate !== null) {
            $groupBy = $compiled->aggregate['group_by'] ?? null;

            if ($groupBy !== null) {
                $counts = $compiled->builder
                    ->select($groupBy, DB::raw('count(*) as count'))
                    ->groupBy($groupBy)
                    ->pluck('count', $groupBy)
                    ->toArray();

                return ['success' => true, 'entity' => $entity, 'group_by' => $groupBy, 'counts' => $counts];
            }

            return ['success' => true, 'entity' => $entity, 'count' => $compiled->builder->count()];
        }

        $total = (clone $compiled->builder)->count();
        $models = $compiled->builder
            ->with($compiled->relations)
            ->offset($compiled->offset)
            ->limit($compiled->limit)
            ->get();
        $rows = $models->map(fn (Model $m) => $this->formatRow($m, $compiled->fields, $compiled->relations))->all();
        $returned = count($rows);
        $hasMore = ($compiled->offset + $returned) < $total;

        return [
            'success' => true,
            'entity' => $entity,
            'count' => $returned,
            'total' => $total,
            'offset' => $compiled->offset,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $compiled->offset + $returned : null,
            'rows' => $rows,
        ];
    }

    /**
     * Delegate an un-catalogued entity to the legacy QueryTribesDataTool, translating
     * the structured filter list into the legacy field=>value map.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function delegateToLegacy(string $entity, array $parameters): mixed
    {
        if ($this->legacyFallback === null) {
            return [
                'success' => false,
                'error' => "Неизвестная сущность '{$entity}'.",
                'entities' => $this->allEntities(),
            ];
        }

        $legacyParams = $parameters;
        $filters = $parameters['filters'] ?? null;

        // Normalize the structured [{field, value}] list into the legacy {field: value} map,
        // AND resolve symbolic actor refs ("me") → the actor id. Without this the legacy tool
        // receives a literal "me" and puts it straight into SQL (e.g. profile_id = me → error).
        if (is_array($filters)) {
            $map = [];
            if (array_is_list($filters)) {
                foreach ($filters as $filter) {
                    if (is_array($filter) && isset($filter['field'])) {
                        $map[$filter['field']] = $this->resolveActorRef($filter['value'] ?? null);
                    }
                }
            } else {
                foreach ($filters as $key => $value) {
                    $map[$key] = $this->resolveActorRef($value);
                }
            }
            $legacyParams['filters'] = $map;
        }

        return $this->legacyFallback->execute($legacyParams);
    }

    /**
     * @param  list<string>  $fields
     * @param  list<string>  $relations
     * @return array<string, mixed>
     */
    private function formatRow(Model $model, array $fields, array $relations): array
    {
        $row = [];

        foreach ($fields as $field) {
            $value = $model->getAttribute($field);
            $row[$field] = $value instanceof \DateTimeInterface
                ? $value->format(DATE_ATOM)
                : (is_scalar($value) || $value === null ? $value : (string) $value);
        }

        foreach ($relations as $relation) {
            $related = $model->relationLoaded($relation) ? $model->getRelation($relation) : $model->{$relation};
            $row['→'.$relation] = $related?->name ?? $related?->email ?? ($related?->id ? '#'.$related->id : null);
        }

        return $row;
    }

    /** Resolve a symbolic actor reference ("me"/"self") to the acting user id. */
    private function resolveActorRef(mixed $value): mixed
    {
        if (is_string($value) && in_array(mb_strtolower($value), ['me', 'self', 'current', 'current_user', 'я'], true)) {
            return $this->user->id;
        }

        return $value;
    }

    /** @return list<string> */
    private function legacyEntities(): array
    {
        return $this->legacyFallback?->supportedEntities() ?? [];
    }

    /** @return list<string> */
    private function allEntities(): array
    {
        return array_values(array_unique(array_merge($this->catalog->entityKeys(), $this->legacyEntities())));
    }
}
