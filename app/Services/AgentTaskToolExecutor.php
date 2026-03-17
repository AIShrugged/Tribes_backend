<?php

namespace App\Services;

use App\Models\AgentTask;
use App\Models\User;
use App\Services\Agent\AgentToolRegistrar;
use App\Services\Agent\Tools\ToolRegistry;

class AgentTaskToolExecutor
{
    public function __construct(
        private readonly AgentToolRegistrar $toolRegistrar,
    ) {}

    public function describeTools(AgentTask $task, User $user): array
    {
        $registry = $this->makeRegistry($task, $user);

        return $registry->getToolsForLLM();
    }

    public function execute(AgentTask $task, User $user, string $toolName, ?array $arguments = null): mixed
    {
        $registry = $this->makeRegistry($task, $user);
        $tool = $registry->get($toolName);

        if (! $tool) {
            return [
                'success' => false,
                'error' => "Tool '{$toolName}' is not allowed for this task",
            ];
        }

        return $tool->execute($arguments ?? []);
    }

    private function makeRegistry(AgentTask $task, User $user): ToolRegistry
    {
        $registry = new ToolRegistry;
        $this->toolRegistrar->registerDefaults($registry, $user, 'web');

        $allowed = $task->effectiveAllowedTools();
        $filtered = new ToolRegistry;

        foreach ($registry->getAll() as $tool) {
            if (is_array($allowed) && in_array($tool->getName(), $allowed, true)) {
                $filtered->register($tool);
            }
        }

        return $filtered;
    }
}
