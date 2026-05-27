<?php

namespace App\Services\Issue;

use App\Models\Team;
use App\Models\User;

/**
 * Abstracts the source of extracted issues so IssueMergeService can work with
 * both CalendarEvent-based (meeting transcript) and TaskDataUpload-based (file
 * upload) flows without duplicating create/update/dedup logic.
 */
interface IssueSourceContext
{
    public function sourceableType(): string;
    public function sourceableId(): int;

    /** Title shown in LLM dedup payload and log messages (meeting title or filename). */
    public function sourceTitle(): string;

    /** Date string for LLM dedup payload and IssueComment content. */
    public function sourceDate(): string;

    /** Resolve assignee user ID from a name. CalendarEvent uses event profiles + team; TaskDataUpload uses team only. */
    public function resolveAssigneeId(?string $assigneeName, Team $team): ?int;

    /** Resolve the author user ID for a new issue. Falls back to $fallback if unresolved. */
    public function resolveAuthorUserId(?string $authorName, User $fallback): int;

    /** Resolve the comment author for an update. Falls back to $fallback if unresolved. */
    public function resolveCommentAuthorUserId(?string $authorName, User $fallback): int;

    /** Build IssueComment content string for an issue update. */
    public function buildCommentContent(string $updateDescription): string;

    /** Calendar event ID for IssueComment (null for non-meeting sources). */
    public function calendarEventIdForComment(): ?int;
}
