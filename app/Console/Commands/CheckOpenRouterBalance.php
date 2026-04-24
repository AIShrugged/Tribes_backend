<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * @deprecated This command is no longer functional.
 *
 * Anthropic does not expose a public balance/credits API.
 * Monitor API usage at console.anthropic.com.
 */
class CheckOpenRouterBalance extends Command
{
    protected $signature = 'openrouter:check-balance {--morning : Always send full report}';

    protected $description = '[Deprecated] Balance monitoring is not available for Anthropic API. Monitor usage at console.anthropic.com.';

    public function handle(): int
    {
        $this->warn('This command is deprecated.');
        $this->line('Anthropic does not provide a public balance/credits API.');
        $this->line('Please monitor your usage at https://console.anthropic.com.');

        return self::SUCCESS;
    }
}
