<?php

namespace App\Services\Agent\Catalog;

/**
 * Read-only access to the agent data catalog (config/agent_catalog.php).
 *
 * The catalog is the single source of truth for what the agent may READ and what
 * each field means. Forbidden data is simply ABSENT from the catalog
 * (deny-by-default). Enum class-strings in the overlay are resolved to concrete
 * values here (derive-don't-duplicate).
 */
class CatalogService
{
    /** @return list<string> */
    public function entityKeys(): array
    {
        return array_keys((array) config('agent_catalog.entities', []));
    }

    /** @return array<string, mixed>|null */
    public function entity(string $key): ?array
    {
        $entity = config("agent_catalog.entities.{$key}");

        if (! is_array($entity)) {
            return null;
        }

        foreach ($entity['fields'] ?? [] as $name => $field) {
            if (isset($field['enum']) && is_string($field['enum']) && enum_exists($field['enum'])) {
                $entity['fields'][$name]['enum'] = array_map(
                    static fn ($case) => $case->value,
                    ($field['enum'])::cases(),
                );
            }
        }

        return $entity;
    }

    public function modelClass(string $key): ?string
    {
        return $this->entity($key)['model'] ?? null;
    }

    /** Name of the Eloquent local scope `scopeXxx(Builder, User)` enforcing tenancy. */
    public function scopeMethod(string $key): ?string
    {
        return $this->entity($key)['scope'] ?? null;
    }

    /** @return array<string, mixed> */
    public function fields(string $key): array
    {
        return $this->entity($key)['fields'] ?? [];
    }

    public function hasField(string $key, string $field): bool
    {
        return array_key_exists($field, $this->fields($key));
    }

    /** @return array<string, mixed> */
    public function relations(string $key): array
    {
        return $this->entity($key)['relations'] ?? [];
    }

    /** @return array<string, mixed>|null */
    public function relation(string $key, string $name): ?array
    {
        return $this->relations($key)[$name] ?? null;
    }

    /** @return array<string, string> */
    public function traps(string $key): array
    {
        return $this->entity($key)['traps'] ?? [];
    }

    public function trap(string $key, string $field): ?string
    {
        return $this->traps($key)[$field] ?? null;
    }

    /**
     * The "menu" handed to the LLM for one entity — business-meaningful fields with
     * descriptions and trap warnings, never raw FK/column dumps. Null if unknown.
     *
     * @return array<string, mixed>|null
     */
    public function describe(string $key): ?array
    {
        $entity = $this->entity($key);

        if ($entity === null) {
            return null;
        }

        return [
            'entity' => $key,
            'label' => $entity['label'] ?? $key,
            'description' => $entity['description'] ?? null,
            'fields' => array_map(
                static fn ($f) => array_filter([
                    'type' => $f['type'] ?? 'string',
                    'description' => $f['description'] ?? null,
                    'enum' => $f['enum'] ?? null,
                ], static fn ($v) => $v !== null),
                $entity['fields'] ?? [],
            ),
            'relations' => array_map(
                static fn ($r) => array_filter([
                    'target' => $r['target'] ?? null,
                    'description' => $r['description'] ?? null,
                ], static fn ($v) => $v !== null && $v !== ''),
                $entity['relations'] ?? [],
            ),
            'traps' => $entity['traps'] ?? [],
        ];
    }

    /** @return list<array{entity:string,label:string,description:string|null}> */
    public function menu(): array
    {
        return array_map(fn ($key) => [
            'entity' => $key,
            'label' => $this->entity($key)['label'] ?? $key,
            'description' => $this->entity($key)['description'] ?? null,
        ], $this->entityKeys());
    }
}
