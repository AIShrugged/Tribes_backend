<?php

namespace App\Services\Agent;

use App\Models\TelegramUser;

class MemoryService
{
    /**
     * Compose memory context for LLM prompt
     */
    public function composeMemoryContext(TelegramUser $telegramUser): string
    {
        $memoryText = $telegramUser->getMemoryText();

        if (!$memoryText) {
            return "## Previous Context\n\nNo previous memories about this user. This is your first interaction with them.";
        }

        return "## Previous Context\n\nWhat you know about this user from previous interactions:\n\n{$memoryText}";
    }
}