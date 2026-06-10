<?php

namespace App\Support;

/**
 * Single source of truth for collapsing the two upload kinds' internal
 * lifecycles into the unified Upload Log status exposed by the feed/detail.
 *
 *   task_data : queued|processing|extracting|analyzing|deduplicating -> processing
 *   transcript: pending                                              -> processing
 *   both      : done -> done, failed -> failed
 *
 * Anything not explicitly terminal is treated as in-progress.
 */
class UploadStatus
{
    public const PROCESSING = 'processing';
    public const REVIEW = 'review';
    public const DONE = 'done';
    public const FAILED = 'failed';

    /** The normalized values, e.g. for FormRequest Rule::in validation. */
    public const NORMALIZED = [self::PROCESSING, self::REVIEW, self::DONE, self::FAILED];

    public static function normalize(string $raw): string
    {
        return match ($raw) {
            'done'           => self::DONE,
            'failed'         => self::FAILED,
            'rejected'       => self::FAILED, // discarded-in-review is terminal; UI shows the message
            'pending_review' => self::REVIEW,
            default          => self::PROCESSING,
        };
    }

    /**
     * The raw statuses (per upload type) that map to a given normalized value.
     * Used to translate a normalized `status` filter into a per-table whereIn.
     *
     * @return array<int, string>
     */
    public static function rawStatusesFor(string $normalized, string $type): array
    {
        $byType = [
            'task_data'  => ['queued', 'processing', 'extracting', 'analyzing', 'deduplicating', 'pending_review', 'done', 'failed', 'rejected'],
            'transcript' => ['pending', 'processing', 'pending_review', 'done', 'failed', 'rejected'],
        ];

        return array_values(array_filter(
            $byType[$type] ?? [],
            static fn (string $raw) => self::normalize($raw) === $normalized,
        ));
    }
}
