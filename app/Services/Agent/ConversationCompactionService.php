<?php

namespace App\Services\Agent;

use Illuminate\Support\Collection;

class ConversationCompactionService
{
    public function __construct(
        private readonly ConversationCompactionSnapshotService $snapshotService,
    ) {}

    public function compact(Collection $history, ?string $conversationKey = null): CompactedHistory
    {
        $keepRecent = (int) config('agent.compaction.keep_recent_messages', 8);
        $maxSummaryChars = (int) config('agent.compaction.max_summary_chars', 2500);

        if ($history->count() <= $keepRecent) {
            return new CompactedHistory($history->values());
        }

        $olderMessages = $history->slice(0, $history->count() - $keepRecent)->values();
        $recentMessages = $history->slice(-$keepRecent)->values();

        $summary = $conversationKey
            ? $this->snapshotService->resolveSummary($conversationKey, $olderMessages, $keepRecent, $maxSummaryChars)
            : $this->snapshotService->buildEphemeralSummary($olderMessages, $maxSummaryChars);

        if ($summary === null || $summary === '') {
            return new CompactedHistory($recentMessages);
        }

        return new CompactedHistory(
            $recentMessages,
            "Earlier conversation summary:\n".$summary
        );
    }
}
