<?php

namespace App\Services\Agent;

use App\Models\ConversationCompactionSnapshot;
use Illuminate\Support\Collection;

class ConversationCompactionSnapshotService
{
    public function resolveSummary(
        string $conversationKey,
        Collection $olderMessages,
        int $keepRecent,
        int $maxSummaryChars,
    ): ?string {
        if ($olderMessages->isEmpty()) {
            return null;
        }

        $snapshot = ConversationCompactionSnapshot::query()
            ->where('conversation_key', $conversationKey)
            ->where('keep_recent_messages', $keepRecent)
            ->first();

        $lastOlderMessageId = $this->messageId($olderMessages->last());
        $messageCount = $olderMessages->count();

        if (
            $snapshot &&
            $snapshot->message_count === $messageCount &&
            $snapshot->last_message_id === $lastOlderMessageId
        ) {
            return $snapshot->summary;
        }

        $summary = $this->tryIncrementalSummary($snapshot, $olderMessages, $maxSummaryChars)
            ?? $this->buildSummary($olderMessages, $maxSummaryChars);

        ConversationCompactionSnapshot::updateOrCreate(
            [
                'conversation_key' => $conversationKey,
                'keep_recent_messages' => $keepRecent,
            ],
            [
                'message_count' => $messageCount,
                'last_message_id' => $lastOlderMessageId,
                'summary' => $summary,
            ]
        );

        return $summary;
    }

    public function buildEphemeralSummary(Collection $olderMessages, int $maxSummaryChars): ?string
    {
        return $this->buildSummary($olderMessages, $maxSummaryChars);
    }

    private function tryIncrementalSummary(
        ?ConversationCompactionSnapshot $snapshot,
        Collection $olderMessages,
        int $maxSummaryChars,
    ): ?string {
        if (! $snapshot || $snapshot->last_message_id === null || $snapshot->summary === '') {
            return null;
        }

        $lastIndex = $olderMessages->search(
            fn ($message) => $this->messageId($message) === (int) $snapshot->last_message_id
        );

        if ($lastIndex === false || $lastIndex === $olderMessages->count() - 1) {
            return null;
        }

        $newOlderMessages = $olderMessages->slice($lastIndex + 1)->values();
        $appended = $this->buildSummary($newOlderMessages, $maxSummaryChars);

        if ($appended === null) {
            return $snapshot->summary;
        }

        $combined = $snapshot->summary."\n".$appended;

        if (mb_strlen($combined) > $maxSummaryChars) {
            return null;
        }

        return $combined;
    }

    private function buildSummary(Collection $messages, int $maxSummaryChars): ?string
    {
        $summaryLines = [];
        foreach ($messages as $message) {
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
            return null;
        }

        if (mb_strlen($summary) > $maxSummaryChars) {
            $summary = mb_substr($summary, 0, $maxSummaryChars).'...';
        }

        return $summary;
    }

    private function messageId(mixed $message): ?int
    {
        $id = $message->id ?? null;

        return is_numeric($id) ? (int) $id : null;
    }
}
