<?php

namespace App\Services\Agent\Tools;

use stdClass;

class ToolRegistry
{
    /**
     * @var array<string, ToolInterface>
     */
    private array $tools = [];

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    public function getAll(): array
    {
        return $this->tools;
    }

    public function clear(): void
    {
        $this->tools = [];
    }

    /**
     * Drop every registered tool whose name isn't in $keepNames. Used by the
     * two-phase tool router to prune the toolset before exposing it to the LLM.
     *
     * @param  array<int, string>  $keepNames
     */
    public function keepOnly(array $keepNames): void
    {
        $keep = array_flip($keepNames);
        $this->tools = array_filter(
            $this->tools,
            static fn (string $name): bool => isset($keep[$name]),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Get tools in OpenAI function calling format
     */
    public function getToolsForLLM(): array
    {
        return array_values(array_map(function (ToolInterface $tool) {
            return [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $this->normalizeSchema($tool->getParameters()),
                ],
            ];
        }, $this->tools));
    }

    /**
     * Normalize JSON Schema so empty PHP arrays that represent objects encode as {} instead of [].
     */
    private function normalizeSchema(mixed $value, ?string $parentKey = null): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($value === [] && in_array($parentKey, ['properties', '$defs', 'definitions'], true)) {
            return new stdClass;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeSchema($item, is_string($key) ? $key : $parentKey);
        }

        return $value;
    }
}
