<?php

namespace App\Services\Followup\Prompts;

use App\Services\Followup\FollowupPromptInterface;

class SharedStayfittV1Prompt implements FollowupPromptInterface
{

    public function getSystemPrompt(): string
    {
        return <<<TXT
            Ты — ИИ-коуч по методике StayFitt.
            Твоя задача — анализировать диалог и возвращать JSON строго по заданной схеме.
            Ответ должен содержать валидный JSON.
        TXT;
    }

    public function getUserPrompt(): string
    {
        return file_get_contents(resource_path('/prompts/shared_stayfitt_v1_prompt.md'));
    }
}
