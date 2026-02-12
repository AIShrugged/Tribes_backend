<?php

namespace App\Services\Agent;

use App\Enums\InsightCategory;
use App\Enums\InsightContextType;
use App\Models\InsightProfile;
use App\Models\InsightShortTerm;
use App\Models\TelegramUser;
use Illuminate\Support\Collection;

class MemoryService
{
    /**
     * Compose memory context for LLM prompt from InsightShortTerm and InsightProfile
     */
    public function composeMemoryContext(TelegramUser $telegramUser): string
    {
        // 1. Check if user has linked account
        $email = $telegramUser->user?->email;
        if (! $email) {
            return "## Previous Context\n\nNo user account linked. Memory unavailable.";
        }

        // 2. Read ShortTerm memory (all context types)
        $shortTermMemories = InsightShortTerm::where('email', $email)
            ->active()
            ->get();

        // 3. Read Profile categories (long-term characteristics)
        $profiles = InsightProfile::where('email', $email)
            ->whereIn('category', [
                InsightCategory::COMMUNICATION_STYLE,
                InsightCategory::GOALS_MOTIVATIONS,
            ])
            ->get();

        // 4. Format combined context
        return $this->formatContext($shortTermMemories, $profiles);
    }

    /**
     * Format combined context from ShortTerm and Profile data
     */
    private function formatContext(Collection $shortTermMemories, Collection $profiles): string
    {
        $lines = ["## Previous Context\n"];

        // Short-term memories by context type
        if ($shortTermMemories->isNotEmpty()) {
            foreach ($shortTermMemories as $memory) {
                if (! empty($memory->content['text'])) {
                    $contextLabel = str_replace('_', ' ', $memory->context_type->value);
                    $lines[] = "### " . ucwords($contextLabel);
                    $lines[] = $memory->content['text'];
                    $lines[] = '';
                }
            }
        }

        // Long-term (stable characteristics)
        if ($profiles->isNotEmpty()) {
            $lines[] = '### What you know about this user:';
            foreach ($profiles as $profile) {
                $lines[] = "\n[{$profile->category->value}]";
                $lines[] = json_encode($profile->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
        }

        // No memories at all
        if (count($lines) === 1) {
            return "## Previous Context\n\nNo previous memories. First interaction.";
        }

        return implode("\n", $lines);
    }
}
