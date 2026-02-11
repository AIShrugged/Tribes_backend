<?php

namespace App\Services\Agent\Tools;

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
                    'parameters' => $tool->getParameters(),
                ],
            ];
        }, $this->tools));
    }
}