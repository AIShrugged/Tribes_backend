<?php

namespace Tests\Unit;

use App\Enums\MeetingTaskStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for the MeetingTaskStatus enum.
 *
 * Covers:
 * - All expected cases are present (including the new REVIEWED case)
 * - tryFrom / from behaviour for each status value
 * - Values used in IssueRequest validation are consistent with the enum
 */
class MeetingTaskStatusTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Enum case existence
    // -------------------------------------------------------------------------

    #[Test]
    public function enum_contains_reviewed_case(): void
    {
        $this->assertSame('reviewed', MeetingTaskStatus::REVIEWED->value);
    }

    #[Test]
    public function enum_contains_all_expected_cases(): void
    {
        $expected = ['open', 'in_progress', 'paused', 'reviewed', 'review', 'reopen', 'done'];

        $actual = array_map(fn (MeetingTaskStatus $s) => $s->value, MeetingTaskStatus::cases());

        foreach ($expected as $status) {
            $this->assertContains(
                $status,
                $actual,
                "Expected status '{$status}' to be present in MeetingTaskStatus enum cases."
            );
        }
    }

    // -------------------------------------------------------------------------
    // tryFrom / from
    // -------------------------------------------------------------------------

    #[Test]
    #[DataProvider('validStatusProvider')]
    public function try_from_returns_enum_for_valid_value(string $value, MeetingTaskStatus $expected): void
    {
        $this->assertSame($expected, MeetingTaskStatus::tryFrom($value));
    }

    #[Test]
    public function try_from_returns_null_for_unknown_value(): void
    {
        $this->assertNull(MeetingTaskStatus::tryFrom('cancelled'));
        $this->assertNull(MeetingTaskStatus::tryFrom('pending'));
        $this->assertNull(MeetingTaskStatus::tryFrom(''));
    }

    #[Test]
    public function from_resolves_reviewed_correctly(): void
    {
        $status = MeetingTaskStatus::from('reviewed');

        $this->assertSame(MeetingTaskStatus::REVIEWED, $status);
        $this->assertSame('reviewed', $status->value);
    }

    // -------------------------------------------------------------------------
    // Enum value list (used by UpdateTaskStatusTool / IssueRequest)
    // -------------------------------------------------------------------------

    #[Test]
    public function cases_returns_reviewed_among_valid_tool_statuses(): void
    {
        $values = array_map(fn (MeetingTaskStatus $s) => $s->value, MeetingTaskStatus::cases());

        $this->assertContains('reviewed', $values);
    }

    #[Test]
    public function reviewed_is_distinct_from_review(): void
    {
        $this->assertNotSame(MeetingTaskStatus::REVIEWED, MeetingTaskStatus::REVIEW);
        $this->assertNotSame(MeetingTaskStatus::REVIEWED->value, MeetingTaskStatus::REVIEW->value);
    }

    // -------------------------------------------------------------------------
    // Data providers
    // -------------------------------------------------------------------------

    public static function validStatusProvider(): array
    {
        return [
            'open'        => ['open',        MeetingTaskStatus::OPEN],
            'in_progress' => ['in_progress', MeetingTaskStatus::IN_PROGRESS],
            'paused'      => ['paused',       MeetingTaskStatus::PAUSED],
            'reviewed'    => ['reviewed',     MeetingTaskStatus::REVIEWED],
            'review'      => ['review',       MeetingTaskStatus::REVIEW],
            'reopen'      => ['reopen',       MeetingTaskStatus::REOPEN],
            'done'        => ['done',         MeetingTaskStatus::DONE],
        ];
    }
}