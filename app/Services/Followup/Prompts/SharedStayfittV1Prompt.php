<?php

namespace App\Services\Followup\Prompts;

use App\Services\Followup\FollowupPromptInterface;
use App\Services\LlmPromptService;

class SharedStayfittV1Prompt implements FollowupPromptInterface
{

    public function getSystemPrompt(): string
    {
        return app(LlmPromptService::class)->renderView(
            slug: 'followup.shared_stayfitt_v1.system',
            organizationId: null,
            fallbackView: 'llm-prompts.followup.shared-stayfitt-v1-system',
            name: 'Shared StayFitt v1 system prompt',
        );
    }

    public function getUserPrompt(): string
    {
        return app(LlmPromptService::class)->renderView(
            slug: 'followup.shared_stayfitt_v1.user',
            organizationId: null,
            fallbackView: 'llm-prompts.followup.shared-stayfitt-v1-user',
            name: 'Shared StayFitt v1 user prompt',
        );
    }
}
