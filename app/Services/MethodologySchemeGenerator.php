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
        $promptService = app(LlmPromptService::class);

        $messages = [
            new MessageDTO('system', $promptService->renderView(
                slug: 'methodology.schema.system',
                organizationId: null,
                fallbackView: 'llm-prompts.methodology.schema-system',
                name: 'Methodology schema system prompt',
            )),
            new MessageDTO('user', $promptService->renderView(
                slug: 'methodology.schema.user.'.$version,
                organizationId: null,
                fallbackView: match ($version) {
                    '1' => 'llm-prompts.methodology.schema-user-v1',
                    '2' => 'llm-prompts.methodology.schema-user-v2',
                    default => 'llm-prompts.shared.prompt-body',
                },
                variables: [
                    'methodology' => $methodology,
                    'prompt_body' => $prompt->make($methodology),
                ],
                name: 'Methodology schema user prompt '.$version,
            ))
        ];

        return $this->llm->chat($messages, Setting::get('model.scheme', config('ai.providers.openrouter.models.scheme')), 8096, true);
    }
}
