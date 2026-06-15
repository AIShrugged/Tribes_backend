<?php

namespace Tests\Unit;

use App\Services\Agent\Tools\ToolInterface;
use App\Services\Agent\Tools\ToolRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ToolRegistryKeepOnlyTest extends TestCase
{
    #[Test]
    public function it_keeps_only_named_tools(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->tool('alpha'));
        $registry->register($this->tool('beta'));
        $registry->register($this->tool('gamma'));

        $registry->keepOnly(['alpha', 'gamma', 'nonexistent']);

        $this->assertNotNull($registry->get('alpha'));
        $this->assertNull($registry->get('beta'));
        $this->assertNotNull($registry->get('gamma'));
        $this->assertCount(2, $registry->getAll());
    }

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
                return 'desc';
            }

            public function getParameters(): array
            {
                return [];
            }

            public function execute(?array $parameters): mixed
            {
                return ['success' => true];
            }
        };
    }
}
