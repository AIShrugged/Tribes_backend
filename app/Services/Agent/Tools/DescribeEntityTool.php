<?php

namespace App\Services\Agent\Tools;

use App\Services\Agent\Catalog\CatalogService;

/**
 * Progressive disclosure for the structured-query layer: lists queryable entities,
 * or describes one entity's fields / relations / traps so the agent can build a
 * correct query_data request. Read-only.
 */
class DescribeEntityTool extends AbstractAgentTool
{
    public function __construct(private readonly CatalogService $catalog)
    {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'describe_entity';
    }

    public function getDescription(): string
    {
        return 'Узнать, какие сущности и поля доступны для query_data. Без параметра — список сущностей; '
            .'с параметром entity — поля, связи и «ловушки» этой сущности.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entity' => [
                    'type' => 'string',
                    'description' => 'Сущность для подробного описания (опционально).',
                ],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $entity = $parameters['entity'] ?? null;

        if (! is_string($entity) || $entity === '') {
            return ['success' => true, 'entities' => $this->catalog->menu()];
        }

        $description = $this->catalog->describe($entity);

        if ($description === null) {
            return [
                'success' => false,
                'error' => "Неизвестная сущность '{$entity}'.",
                'entities' => $this->catalog->menu(),
            ];
        }

        return ['success' => true] + $description;
    }
}
