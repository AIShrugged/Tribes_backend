<?php

namespace App\Services\Issue;

use App\Models\CalendarEvent;
use App\Models\Team;
use App\Models\User;
use App\Services\Decisions\DecisionAuthorResolver;
use App\Support\NameNormalizer;

/**
 * Source context for issues extracted from a meeting transcript.
 * Wraps CalendarEvent and preserves the existing behavior of IssueMergeService::persist().
 */
class CalendarEventSourceContext implements IssueSourceContext
{
    public function __construct(
        private readonly CalendarEvent $event,
        private readonly DecisionAuthorResolver $authorResolver,
    ) {
    }

    public function sourceableType(): string
    {
        return CalendarEvent::class;
    }

    public function sourceableId(): int
    {
        return $this->event->id;
    }

    public function sourceTitle(): string
    {
        return $this->event->title ?? '';
    }

    public function sourceDate(): string
    {
        return $this->event->starts_at?->toDateString() ?? now()->toDateString();
    }

    public function resolveAssigneeId(?string $assigneeName, Team $team): ?int
    {
        if (blank($assigneeName)) {
            return null;
        }

        // Pass 1: event profiles (most precise — only matched participants)
        $this->event->loadMissing('profiles.user');
        $user = $this->event->profiles
            ->map(fn ($profile) => $profile->user)
            ->filter()
            ->first(fn (User $u) => $this->nameMatches($u->name, $assigneeName));

        if ($user) {
            return $user->id;
        }

        // Pass 2: all team members
        return $this->resolveFromTeam($assigneeName, $team);
    }

    public function resolveAuthorUserId(?string $authorName, User $fallback): int
    {
        if (blank($authorName)) {
            return $fallback->id;
        }

        $resolved = $this->authorResolver->resolve($this->event, $authorName);

        return $resolved['user_id'] ?? $fallback->id;
    }

    public function resolveCommentAuthorUserId(?string $authorName, User $fallback): int
    {
        if (!blank($authorName)) {
            $resolved = $this->authorResolver->resolve($this->event, $authorName)['user_id'] ?? null;
            if ($resolved) {
                return $resolved;
            }
        }

        return $fallback->id;
    }

    public function buildCommentContent(string $updateDescription): string
    {
        $dateStr = $this->sourceDate();
        $title   = $this->event->title ?? '';

        return "**Обновление по встрече \"{$title}\" от {$dateStr}:**\n\n{$updateDescription}";
    }

    public function calendarEventIdForComment(): ?int
    {
        return $this->event->id;
    }

    private function resolveFromTeam(string $name, Team $team): ?int
    {
        return $team->users()
            ->get(['users.id', 'users.name'])
            ->first(fn (User $u) => $this->nameMatches($u->name, $name))
            ?->id;
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
