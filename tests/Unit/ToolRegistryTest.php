<?php

namespace Tests\Unit;

use App\Services\Agent\Tools\ToolInterface;
use App\Services\Agent\Tools\ToolRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ToolRegistryTest extends TestCase
{
    #[Test]
    public function it_serializes_empty_schema_properties_as_json_object(): void
    {
        $registry = new ToolRegistry;
        $registry->register(new class implements ToolInterface
        {
            public function getName(): string
            {
                return 'no_args_tool';
            }

            public function getDescription(): string
            {
                return 'Tool without parameters';
            }

            public function getParameters(): array
            {
                return [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ];
            }

            public function execute(?array $parameters): mixed
            {
                return null;
            }
        });

        $tools = $registry->getToolsForLLM();
        $json = json_encode($tools, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('"properties":{}', $json);
    }
}
