<?php

namespace App\Services\Agent;

use App\Enums\AgentTaskType;
use App\Enums\OutputMode;

class AgentRunOptions
{
    public function __construct(
        public readonly ?string $channel = null,
        public readonly OutputMode $outputMode = OutputMode::PLAIN,
        public readonly AgentTaskType $taskType = AgentTaskType::INTERACTIVE,
        public readonly ?string $conversationKey = null,
        public readonly ?string $systemPromptExtension = null,
        public readonly ?\Closure $progressCallback = null,
        public readonly ?int $chatId = null,
        public readonly ?string $agentRunUuid = null,
        public readonly ?int $organizationId = null,
        public readonly bool $enableSqlTool = true,
        public readonly int $maxTokens = 4096,
        public readonly bool $enableThinking = false,
        public readonly ?int $teamId = null,
        public readonly ?int $agentTaskRunId = null,
        public readonly ?array $allowedTools = null,
    ) {}
}
