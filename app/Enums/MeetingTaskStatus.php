<?php

namespace App\Enums;

use InvalidArgumentException;

enum MeetingTaskStatus: string
{
    case OPEN        = 'open';
    case REVIEWED    = 'reviewed';
    case IN_PROGRESS = 'in_progress';
    case PAUSED      = 'paused';
    case REVIEW      = 'review';
    case REOPEN      = 'reopen';
    case DONE        = 'done';

    /**
     * Returns the full transition map.
     *
     * Key   = current status value
     * Value = list of status values that are allowed as the next state
     *
     * Architectural decision:
     *   - open → reviewed  (mandatory review gate before work starts)
     *   - reviewed → in_progress  (work may only begin after review approval)
     *   - open → in_progress is intentionally ABSENT (blocked)
     *
     * @return array<string, string[]>
     */
    public static function allowedTransitions(): array
    {
        return [
            self::OPEN->value        => [self::REVIEWED->value],
            self::REVIEWED->value    => [self::IN_PROGRESS->value, self::OPEN->value],
            self::IN_PROGRESS->value => [self::PAUSED->value, self::REVIEW->value, self::DONE->value],
            self::PAUSED->value      => [self::IN_PROGRESS->value, self::DONE->value],
            self::REVIEW->value      => [self::REOPEN->value, self::DONE->value],
            self::REOPEN->value      => [self::IN_PROGRESS->value, self::DONE->value],
            self::DONE->value        => [],
        ];
    }

    /**
     * Assert that transitioning from $current to $next is allowed.
     *
     * @throws InvalidArgumentException when the transition is not permitted.
     */
    public static function assertCanTransitionTo(string $current, string $next): void
    {
        $map = self::allowedTransitions();

        // If the current status is not in the map at all, allow (legacy/unknown values).
        if (! array_key_exists($current, $map)) {
            return;
        }

        if (! in_array($next, $map[$current], true)) {
            throw new InvalidArgumentException(
                "Invalid status transition from '{$current}' to '{$next}'. "
                . "Allowed next states: [" . implode(', ', $map[$current]) . "]."
            );
        }
    }
}