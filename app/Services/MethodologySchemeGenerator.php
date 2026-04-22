<?php

namespace App\Services;

use App\Domain\DTO\AI\MessageDTO;
use App\Models\Setting;
use App\Services\Methodologies\SchemePromptFactory;

class MethodologySchemeGenerator
{
    public function __construct(
        private OpenRouterClient $llm
    ) {
    }

    public function generate(string $methodology, string $version): string
    {
        $prompt = SchemePromptFactory::resolve($version);

        $messages = [
            new MessageDTO('system', file_get_contents(resource_path('/prompts/methodology_schema_prompt.md'))),
            new MessageDTO('user', $prompt->make($methodology))
        ];

        return $this->llm->chat($messages, Setting::get('model.scheme', config('ai.providers.anthropic.models.scheme')), 8096, true);
    }
}
