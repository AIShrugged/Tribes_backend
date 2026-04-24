<?php

namespace App\Services\Agent;

use App\Enums\InsightCategory;
use App\Enums\InsightContextType;
use App\Models\Channel;
use App\Models\InsightProfile;
use App\Models\InsightShortTerm;
use App\Models\Issue;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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
        $channel = $channelName ?? 'web';
        $cacheKey = "memory_context:{$user->id}:{$channel}";

        return Cache::remember($cacheKey, 300, function () use ($user, $channel) {
            $profile = $this->resolveProfile($user, $channel);

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

            $urgentIssues = Issue::urgentFor($user->id)
                ->orderByRaw('priority DESC, due_date ASC NULLS LAST')
                ->limit(5)
                ->get(['id', 'name', 'priority', 'due_date', 'status']);

            return $this->formatContext($shortTermMemories, $profiles, $urgentIssues);
        });
    }

    public function invalidateMemoryCache(Profile $profile, string $channel): void
    {
        Cache::forget("memory_context:{$profile->user_id}:{$channel}");
    }

    /**
     * Resolve the Profile for a user, optionally through a specific channel.
     */
    private function resolveProfile(User $user, ?string $channelName): ?Profile
    {
        $channelName ??= 'web';

        $channelId  = Channel::idFor($channelName);
        $identifier = $user->resolveChannelIdentifier($channelName);

        return $channelId && $identifier
            ? Profile::where('channel_id', $channelId)->where('channel_identifier', $identifier)->first()
            : null;
    }

    private function formatContext(Collection $shortTermMemories, Collection $profiles, Collection $urgentIssues = new Collection()): string
    {
        $lines = ["## Previous Context\n"];

        $focus = $shortTermMemories->first(
            fn ($m) => $m->context_type === InsightContextType::USER_FOCUS
        );

        if ($focus && ! empty($focus->content['focus_text'])) {
            $lines[] = '### Active Focus';
            $focusLine = $focus->content['focus_text'];
            if (! empty($focus->content['deadline'])) {
                $focusLine .= ' (deadline: ' . $focus->content['deadline'] . ')';
            }
            $lines[] = $focusLine;
            $lines[] = '';
        }

        if ($urgentIssues->isNotEmpty()) {
            $lines[] = '### Urgent Tasks';
            foreach ($urgentIssues as $issue) {
                $tag = $issue->priority >= Issue::PRIORITY_CRITICAL ? '[CRITICAL]' : '[OVERDUE]';
                $due = $issue->due_date ? ' (due: ' . $issue->due_date->toDateString() . ')' : '';
                $lines[] = "- {$tag} [#{$issue->id}] {$issue->name}{$due}";
            }
            $lines[] = '';
        }

        foreach ($shortTermMemories as $memory) {
            if ($memory->context_type === InsightContextType::USER_FOCUS) {
                continue;
            }
            if (! empty($memory->content['text'])) {
                $contextLabel = str_replace('_', ' ', $memory->context_type->value);
                $lines[]      = '### ' . ucwords($contextLabel);
                $lines[]      = $memory->content['text'];
                $lines[]      = '';
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
