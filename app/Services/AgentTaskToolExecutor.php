<?php

namespace App\Services;

use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\User;
use App\Services\Agent\AgentToolRegistrar;
use App\Services\Agent\Tools\ToolRegistry;

class AgentTaskToolExecutor
{
    public function __construct(
        private readonly AgentToolRegistrar $toolRegistrar,
    ) {}

    public function describeTools(AgentTask $task, User $user, ?string $sandboxWorkspacePath = null, ?AgentTaskRun $run = null): array
    {
        $registry = $this->makeRegistry(
            $task,
            $user,
            $sandboxWorkspacePath,
            $task->usesPersistentSandboxWorkspace(),
            $run,
        );

        return $registry->getToolsForLLM();
    }

    public function execute(AgentTask $task, User $user, string $toolName, ?array $arguments = null, ?string $sandboxWorkspacePath = null, ?AgentTaskRun $run = null): mixed
    {
        $registry = $this->makeRegistry(
            $task,
            $user,
            $sandboxWorkspacePath,
            $task->usesPersistentSandboxWorkspace(),
            $run,
        );
        $tool = $registry->get($toolName);

        if (! $tool) {
            return [
                'success' => false,
                'error' => "Tool '{$toolName}' is not allowed for this task",
            ];
        }

        return $tool->execute($arguments ?? []);
    }

    private function makeRegistry(
        AgentTask $task,
        User $user,
        ?string $sandboxWorkspacePath = null,
        bool $preserveSandboxDependencies = false,
        ?AgentTaskRun $run = null,
    ): ToolRegistry
    {
        $registry = new ToolRegistry;
        $this->toolRegistrar->registerDefaults(
            $registry,
            $user,
            'web',
            $sandboxWorkspacePath,
            $preserveSandboxDependencies,
            $task->organization_id,
            $task->team_id,
            agentTaskRunId: $run?->id,
        );
        $this->toolRegistrar->registerAgentTaskTools($registry, $task, $run);

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
