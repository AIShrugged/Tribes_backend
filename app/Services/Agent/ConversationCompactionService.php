<?php

namespace App\Services\Agent;

use Illuminate\Support\Collection;

class ConversationCompactionService
{
    public function compact(Collection $history): CompactedHistory
    {
        $keepRecent = (int) config('agent.compaction.keep_recent_messages', 8);
        $maxSummaryChars = (int) config('agent.compaction.max_summary_chars', 2500);

        if ($history->count() <= $keepRecent) {
            return new CompactedHistory($history->values());
        }

        $olderMessages = $history->slice(0, $history->count() - $keepRecent)->values();
        $recentMessages = $history->slice(-$keepRecent)->values();

        $summaryLines = [];
        foreach ($olderMessages as $message) {
            $role = $message->role === 'user' ? 'User' : 'Assistant';
            $content = trim((string) $message->content);
            if ($content === '') {
                continue;
            }

            $content = preg_replace('/\s+/', ' ', $content) ?? $content;
            $summaryLines[] = sprintf('%s: %s', $role, mb_strimwidth($content, 0, 220, '...'));
        }

        $summary = implode("\n", $summaryLines);
        if ($summary === '') {
            return new CompactedHistory($recentMessages);
        }

        if (mb_strlen($summary) > $maxSummaryChars) {
            $summary = mb_substr($summary, 0, $maxSummaryChars).'...';
        }

        return new CompactedHistory(
            $recentMessages,
            "Earlier conversation summary:\n".$summary
        );
    }
}
