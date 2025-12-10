<?php

namespace App\Services\Methodologies;

use App\Exceptions\AppException;
use App\Services\Methodologies\Prompts\PromptV1;

class SchemePromptFactory
{
    private const PROMPTS = [
        PromptV1::class
    ];

    public static function resolve(string $version): BaseSchemePrompt
    {
        foreach (self::PROMPTS as $prompt) {
            $prompt = new $prompt();

            if ($prompt->getVersion() === $version) {
                return $prompt;
            }
        }

        throw new AppException('Prompt with specified version does not exist', 'PROMPT_INVALID_VERSION');
    }
}
