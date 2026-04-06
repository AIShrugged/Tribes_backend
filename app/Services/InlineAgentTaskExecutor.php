<?php

namespace App\Services;

use App\Enums\AgentTaskType;
use App\Enums\OutputMode;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Services\Agent\AgentRunOptions;
use App\Services\Agent\AgentService;

class InlineAgentTaskExecutor
{
    public function __construct(
        private readonly AgentService $agentService,
        private readonly AgentTaskContextBuilder $contextBuilder,
    ) {}

    public function execute(AgentTask $task, AgentTaskRun $run): string
    {
        $user = $task->user()->first();
        if (! $user) {
            throw new \RuntimeException('Agent task user not found');
        }

        $context = $this->contextBuilder->build($task);

        return $this->agentService->run(
            $user,
            collect(),
            $context['user_prompt'],
            new AgentRunOptions(
                outputMode: OutputMode::from($task->output_mode),
                taskType: AgentTaskType::from($task->agent_task_type),
                conversationKey: 'agent-task:'.$task->id,
                systemPromptExtension: $context['system_prompt_extension'],
                progressCallback: function (string $stage, array $context = []) use ($run) {
                    if ($stage === 'before_tool' && isset($context['tool'])) {
                        $run->updateQuietly([
                            'metadata' => [
                                ...($run->metadata ?? []),
                                'current_tool' => $context['tool'],
                                'current_tool_description' => $context['description'] ?? null,
                                'current_iteration' => $context['iteration'] ?? null,
                            ],
                        ]);
                    }
                },
            )
        );
    }
}
