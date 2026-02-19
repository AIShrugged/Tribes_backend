<?php

namespace App\Services\Agent;

use App\Enums\InsightCategory;
use App\Models\Channel;
use App\Models\InsightProfile;
use App\Models\InsightShortTerm;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Collection;

class MemoryService
{
    /**
     * Compose memory context for LLM prompt.
     *
     * @param User        $user        Internal application user
     * @param string|null $channelName Channel name for profile resolution (e.g. 'telegram').
     *                                 When null, profile is looked up directly by user_id.
     */
    public function composeMemoryContext(User $user, ?string $channelName = null): string
    {
        $profile = $this->resolveProfile($user, $channelName);

        if (! $profile) {
            return "## Previous Context\n\nNo previous memories. First interaction.";
        }

        $shortTermMemories = InsightShortTerm::where('profile_id', $profile->id)
            ->active()
            ->get();

        $profiles = InsightProfile::where('profile_id', $profile->id)
            ->whereIn('category', [
                InsightCategory::COMMUNICATION_STYLE,
                InsightCategory::GOALS_MOTIVATIONS,
            ])
            ->get();

        return $this->formatContext($shortTermMemories, $profiles);
    }

    /**
     * Resolve the Profile for a user, optionally through a specific channel.
     */
    private function resolveProfile(User $user, ?string $channelName): ?Profile
    {
        if (! $channelName) {
            return Profile::where('user_id', $user->id)->first();
        }

        $channelId  = Channel::idFor($channelName);
        $identifier = $user->resolveChannelIdentifier($channelName);

        return $channelId && $identifier
            ? Profile::where('channel_id', $channelId)->where('channel_identifier', $identifier)->first()
            : null;
    }

    /**
     * Format combined context from ShortTerm and Profile data
     */
    private function formatContext(Collection $shortTermMemories, Collection $profiles): string
    {
        $lines = ["## Previous Context\n"];

        if ($shortTermMemories->isNotEmpty()) {
            foreach ($shortTermMemories as $memory) {
                if (! empty($memory->content['text'])) {
                    $contextLabel = str_replace('_', ' ', $memory->context_type->value);
                    $lines[]      = '### ' . ucwords($contextLabel);
                    $lines[]      = $memory->content['text'];
                    $lines[]      = '';
                }
            }
        }

        if ($profiles->isNotEmpty()) {
            $lines[] = '### What you know about this user:';
            foreach ($profiles as $profile) {
                $lines[] = "\n[{$profile->category->value}]";
                $lines[] = json_encode($profile->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
        }

        if (count($lines) === 1) {
            return "## Previous Context\n\nNo previous memories. First interaction.";
        }

        return implode("\n", $lines);
    }
}
