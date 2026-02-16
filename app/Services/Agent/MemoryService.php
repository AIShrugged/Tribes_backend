<?php

namespace App\Services\Agent;

use App\Enums\InsightCategory;
use App\Models\Channel;
use App\Models\InsightProfile;
use App\Models\InsightShortTerm;
use App\Models\Profile;
use App\Models\TelegramUser;
use Illuminate\Support\Collection;

class MemoryService
{
    /**
     * Compose memory context for LLM prompt from InsightShortTerm and InsightProfile
     */
    public function composeMemoryContext(TelegramUser $telegramUser): string
    {
        // 1. Resolve telegram profile (works for both linked and unlinked users)
        $telegramChannelId = Channel::idFor('telegram');

        $profile = $telegramChannelId
            ? Profile::where('channel_id', $telegramChannelId)
                ->where('channel_identifier', (string) $telegramUser->telegram_user_id)
                ->first()
            : null;

        if (! $profile) {
            return "## Previous Context\n\nNo previous memories. First interaction.";
        }

        // 3. Read ShortTerm memory (all context types)
        $shortTermMemories = InsightShortTerm::where('profile_id', $profile->id)
            ->active()
            ->get();

        // 4. Read Profile categories (long-term characteristics)
        $profiles = InsightProfile::where('profile_id', $profile->id)
            ->whereIn('category', [
                InsightCategory::COMMUNICATION_STYLE,
                InsightCategory::GOALS_MOTIVATIONS,
            ])
            ->get();

        // 5. Format combined context
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
