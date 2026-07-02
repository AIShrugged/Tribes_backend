<?php

/*
|--------------------------------------------------------------------------
| Agent Data Catalog (Stage 1 — overlay-first)
|--------------------------------------------------------------------------
|
| Single source of truth for what the agent may READ and what each field
| MEANS. Structure (columns/types) is validated against the real schema by
| CatalogConsistencyTest; descriptions / relations / enums / traps / scope are
| the curated, hand-written overlay.
|
| Deny-by-default: anything NOT listed here is invisible to the structured-query
| layer. Cache-safe — values are scalars / class-strings only (no closures), so
| `php artisan config:cache` works.
|
| - model:     Eloquent model class backing the entity.
| - scope:     name of an Eloquent local scope `scopeXxx(Builder, User)` that
|              TenantScopeGate applies as `$query->xxx($actor)` (fail-closed).
| - fields:    queryable/selectable business fields (NOT raw FK columns). `enum`
|              may be a PHP enum class-string — CatalogService resolves ::cases().
| - relations: traversable relations → real FK column (joins resolved server-side).
| - traps:     columns that exist but must NOT be used naively (with guidance).
|
*/

return [
    'entities' => [

        'tasks' => [
            'label' => 'Задачи',
            'model' => \App\Models\Issue::class,
            'description' => 'Задачи (issues) организации: что нужно сделать, их статус, исполнитель, срок и приоритет.',
            'scope' => 'visibleTo',
            'fields' => [
                'id'          => ['type' => 'integer',  'description' => 'ID задачи'],
                'code'        => ['type' => 'string',   'description' => 'Код задачи вида PREFIX-N (напр. DEV-14) — идентификатор, который видит и называет пользователь. Уникален глобально. Фильтруй по нему, когда пользователь ссылается на задачу кодом.'],
                'number'      => ['type' => 'integer',  'description' => 'Порядковый номер задачи внутри организации (напр. 14 в DEV-14).'],
                'name'        => ['type' => 'string',   'description' => 'Название задачи'],
                'description' => ['type' => 'string',   'description' => 'Описание / ТЗ задачи'],
                'status'      => ['type' => 'string',   'description' => 'Статус задачи', 'enum' => \App\Enums\MeetingTaskStatus::class],
                'priority'    => ['type' => 'integer',  'description' => 'Приоритет: 500=critical, 100=high, 0=normal, -100=low, -500=minimal'],
                'type'        => ['type' => 'string',   'description' => 'Тип: development / organization / epic'],
                'due_date'    => ['type' => 'date',     'description' => 'Срок выполнения. Фильтруй операторами <=/>= для «просроченных»/«ближайших».'],
                'close_date'  => ['type' => 'datetime', 'description' => 'Когда задача была закрыта'],
                'created_at'  => ['type' => 'datetime', 'description' => 'Когда задача создана'],
                'updated_at'  => ['type' => 'datetime', 'description' => 'Последнее обновление. Для «застрявших» задач фильтруй updated_at <= <дата>.'],
            ],
            'relations' => [
                'assignee'     => ['target' => 'users', 'fk' => 'assignee_id',     'description' => 'Исполнитель (пользователь). Фильтровать по user id или "me".'],
                'team'         => ['target' => 'teams', 'fk' => 'team_id',         'description' => 'Команда задачи'],
                'organization' => ['target' => null,    'fk' => 'organization_id', 'description' => 'Организация задачи'],
                'epic'         => ['target' => 'tasks', 'fk' => 'epic_id',         'description' => 'Родительский эпик'],
            ],
            'traps' => [
                'assignee_name' => 'Денормализованное ИМЯ исполнителя из транскрипта (строка), НЕ ссылка на пользователя. '
                    .'Для поиска по исполнителю используй relation "assignee" с user id, НЕ это поле.',
            ],
        ],

    ],
];
