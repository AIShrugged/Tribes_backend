<?php

namespace App\Services\Methodologies\Prompts;

use App\Services\Methodologies\BaseSchemePrompt;

class PromptV1 extends BaseSchemePrompt
{
    protected const VERSION = '1';

    protected function getTemplate(): string
    {
        return 'prompts.methodology_json_schema_v1';
    }
}
