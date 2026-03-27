<?php

namespace App\Services\Methodologies\Prompts;

use App\Services\Methodologies\BaseSchemePrompt;

class PromptV2 extends BaseSchemePrompt
{
    protected const VERSION = '2';

    protected function getTemplate(): string
    {
        return 'prompts.methodology_json_schema_v2';
    }
}
