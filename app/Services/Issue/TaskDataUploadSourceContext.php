<?php

namespace App\Services\Issue;

use App\Models\TaskDataUpload;
use App\Models\Team;
use App\Models\User;
use App\Support\NameNormalizer;

/**
 * Source context for issues extracted from a task-data file upload.
 * No CalendarEvent, no meeting profiles, no DecisionAuthorResolver.
 * Assignee resolution uses team members only.
 */
class TaskDataUploadSourceContext implements IssueSourceContext
{
    public function __construct(
        private readonly TaskDataUpload $upload,
    ) {
    }

    public function sourceableType(): string
    {
        return TaskDataUpload::class;
    }

    public function sourceableId(): int
    {
        return $this->upload->id;
    }

    public function sourceTitle(): string
    {
        return $this->upload->original_filename;
    }

    public function sourceDate(): string
    {
        return $this->upload->created_at?->toDateString() ?? now()->toDateString();
    }

    public function resolveAssigneeId(?string $assigneeName, Team $team): ?int
    {
        if (blank($assigneeName)) {
            return null;
        }

        return $team->users()
            ->get(['users.id', 'users.name'])
            ->first(fn (User $u) => $this->nameMatches($u->name, $assigneeName))
            ?->id;
    }

    public function resolveAuthorUserId(?string $authorName, User $fallback): int
    {
        return $fallback->id;
    }

    public function resolveCommentAuthorUserId(?string $authorName, User $fallback): int
    {
        return $fallback->id;
    }

    public function buildCommentContent(string $updateDescription): string
    {
        $dateStr = $this->sourceDate();
        $filename = $this->upload->original_filename;

        return "**Update from uploaded data \"{$filename}\" ({$dateStr}):**\n\n{$updateDescription}";
    }

    public function calendarEventIdForComment(): ?int
    {
        return null;
    }

    private function nameMatches(string $userName, string $needle): bool
    {
        $haystack = NameNormalizer::normalize($userName);
        $needle   = NameNormalizer::normalize($needle);

        return $haystack === $needle
            || str_contains($haystack, $needle)
            || str_contains($needle, $haystack);
    }
}
