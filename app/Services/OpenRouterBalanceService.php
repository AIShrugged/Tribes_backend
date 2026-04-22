<?php

namespace App\Services;

/**
 * @deprecated This service was specific to OpenRouter.
 *
 * Anthropic does not expose a public balance/credits API, so balance
 * monitoring via this service is no longer available. The
 * CheckOpenRouterBalance command has been disabled accordingly.
 *
 * Monitor usage through the Anthropic Console at console.anthropic.com.
 */
class OpenRouterBalanceService
{
    public function fetch(): array
    {
        throw new \RuntimeException(
            'Balance checking is not supported with the Anthropic API. ' .
            'Please monitor usage at console.anthropic.com.'
        );
    }
}
