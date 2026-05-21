<?php

namespace App\Services\Issue;

use App\Models\IssueNudge;

final readonly class NextNudgeAction
{
    public function __construct(
        public string $kind,        // IssueNudge::KIND_*
        public int $attempt,        // 1 | 2
        public string $templateKey, // IssueNudge::TEMPLATE_*
    ) {}

    public static function exec1(): self
    {
        return new self(IssueNudge::KIND_EXECUTOR, 1, IssueNudge::TEMPLATE_EXEC_1);
    }

    public static function exec2(): self
    {
        return new self(IssueNudge::KIND_EXECUTOR, 2, IssueNudge::TEMPLATE_EXEC_2);
    }

    public static function escalation(): self
    {
        return new self(IssueNudge::KIND_MANAGER, 1, IssueNudge::TEMPLATE_ESCALATION);
    }
}
