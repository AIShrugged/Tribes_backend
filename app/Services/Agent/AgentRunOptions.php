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
    ) {}
}
