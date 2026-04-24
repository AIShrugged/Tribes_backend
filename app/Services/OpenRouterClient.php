<?php

namespace App\Services;

use App\Domain\DTO\AI\MessageDTO;

/**
 * @deprecated Use AnthropicClient instead.
 *
 * This class is kept as a thin alias so existing injection bindings
 * and static call sites continue to work without modification.
 * All logic has been moved to AnthropicClient.
 */
class OpenRouterClient extends AnthropicClient
{
    // Inherits chat(), chatWithTools(), and all helpers from AnthropicClient.
}
