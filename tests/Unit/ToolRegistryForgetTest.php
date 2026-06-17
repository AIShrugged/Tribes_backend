<?php

namespace Tests\Unit;

use App\Services\Agent\Tools\ToolInterface;
use App\Services\Agent\Tools\ToolRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ToolRegistryForgetTest extends TestCase
{
    private function tool(string $name): ToolInterface
    {
        return new class($name) implements ToolInterface
        {
            public function __construct(private string $name) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return 'x';
            }

            public function getParameters(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(?array $parameters): mixed
            {
                return null;
            }
        };
    }

    #[Test]
    public function forget_removes_named_tools_and_keeps_the_rest(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->tool('save_commit_report'));
        $registry->register($this->tool('update_commit_report_item'));
        $registry->register($this->tool('github_list_commits'));
        $registry->register($this->tool('get_issue_candidates'));

        // includes a name that isn't registered — must be a no-op, not an error
        $registry->forget(['save_commit_report', 'update_commit_report_item', 'not_registered']);

        $this->assertNull($registry->get('save_commit_report'));
        $this->assertNull($registry->get('update_commit_report_item'));
        $this->assertNotNull($registry->get('github_list_commits'));
        $this->assertNotNull($registry->get('get_issue_candidates'));
        $this->assertCount(2, $registry->getAll());
    }
}
