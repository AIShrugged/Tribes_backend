<?php

namespace Tests\Unit;

use App\Models\Issue;
use App\Services\Issue\IssueContentValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IssueContentValidatorTest extends TestCase
{
    private IssueContentValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new IssueContentValidator();
    }

    private function makeIssue(string $description, string $type = 'development'): Issue
    {
        // Build in-memory; we don't need DB for validation
        $issue = new Issue();
        $issue->description = $description;
        $issue->type = $type;
        return $issue;
    }

    #[Test]
    public function it_detects_no_missing_when_all_sections_present_russian(): void
    {
        $issue = $this->makeIssue(
            "## Контекст\nЧтобы пилот заработал.\n\n".
            "## Пункты\n1. Шаг один\n2. Шаг два\n\n".
            "## Definition of done\nPR замержен."
        );

        $this->assertSame([], $this->validator->validate($issue));
    }

    #[Test]
    public function it_detects_no_missing_when_all_sections_present_english(): void
    {
        $issue = $this->makeIssue(
            "## Context\nWhy.\n\n".
            "## Steps\n1. First\n2. Second\n\n".
            "## Definition of done\nDeployed."
        );

        $this->assertSame([], $this->validator->validate($issue));
    }

    #[Test]
    public function it_detects_missing_context_section(): void
    {
        $issue = $this->makeIssue(
            "## Пункты\n1. a\n\n## Definition of done\nGo."
        );

        $this->assertSame(['context'], $this->validator->validate($issue));
    }

    #[Test]
    public function it_detects_missing_dod_for_task_but_not_for_epic(): void
    {
        $description = "## Контекст\nстарт\n\n## Пункты\n1. a";

        $task = $this->makeIssue($description, 'development');
        $epic = $this->makeIssue($description, 'epic');

        $this->assertSame(['dod'], $this->validator->validate($task));
        $this->assertSame([], $this->validator->validate($epic));
    }

    #[Test]
    public function it_treats_whitespace_only_section_as_missing(): void
    {
        $issue = $this->makeIssue(
            "## Контекст\n   \n\n## Пункты\n1. a\n\n## Definition of done\nGo."
        );

        $this->assertSame(['context'], $this->validator->validate($issue));
    }

    #[Test]
    public function it_treats_placeholder_as_missing(): void
    {
        $issue = $this->makeIssue(
            "## Контекст\nTBD\n\n## Пункты\n1. a\n\n## Definition of done\nGo."
        );

        $this->assertSame(['context'], $this->validator->validate($issue));
    }

    #[Test]
    public function it_accepts_bold_fallback_heading(): void
    {
        $issue = $this->makeIssue(
            "**Контекст**\nстарт\n\n**Пункты**\n1. a\n\n**Definition of done**\nGo."
        );

        $this->assertSame([], $this->validator->validate($issue));
    }

    #[Test]
    public function it_accepts_trailing_colon_on_heading(): void
    {
        $issue = $this->makeIssue(
            "## Контекст:\nстарт\n\n## Пункты:\n1. a\n\n## Definition of done:\nGo."
        );

        $this->assertSame([], $this->validator->validate($issue));
    }

    #[Test]
    public function it_is_case_insensitive_on_heading_aliases(): void
    {
        $issue = $this->makeIssue(
            "## контекст\nстарт\n\n## ПУНКТЫ\n1. a\n\n## definition of DONE\nGo."
        );

        $this->assertSame([], $this->validator->validate($issue));
    }

    #[Test]
    public function it_returns_all_missing_when_description_blank(): void
    {
        $taskBlank = $this->makeIssue('', 'development');
        $this->assertSame(['context', 'items', 'dod'], $this->validator->validate($taskBlank));

        $epicBlank = $this->makeIssue('', 'epic');
        $this->assertSame(['context', 'items'], $this->validator->validate($epicBlank));
    }

    #[Test]
    public function it_returns_all_missing_when_description_null(): void
    {
        $issue = $this->makeIssue('', 'development');
        $issue->description = null;
        $this->assertSame(['context', 'items', 'dod'], $this->validator->validate($issue));
    }
}
