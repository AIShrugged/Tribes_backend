<?php

namespace App\Services\Agent\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Base class for Agent Tools that are also exposed as MCP Tools.
 *
 * Subclasses must implement ToolInterface methods:
 * - getName(): string
 * - getDescription(): string
 * - getParameters(): array   (OpenAI JSON Schema format)
 * - execute(?array $parameters): mixed
 *
 * This class automatically:
 * - Sets MCP name/description from getName()/getDescription()
 * - Converts getParameters() (OpenAI format) to MCP JsonSchema format
 * - Delegates MCP handle() to execute()
 */
abstract class AbstractAgentTool extends Tool implements ToolInterface
{
    public function __construct()
    {
        $this->name = $this->getName();
        $this->description = $this->getDescription();
    }

    /**
     * MCP: handle tool call by delegating to execute().
     */
    public function handle(Request $request): Response
    {
        return Response::structured(
            $this->execute($request->all())
        );
    }

    /**
     * MCP: convert OpenAI JSON Schema from getParameters() to laravel/mcp format.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $params = $this->getParameters();
        $required = $params['required'] ?? [];
        $result = [];

        foreach ($params['properties'] ?? [] as $name => $prop) {
            $field = match ($prop['type'] ?? 'string') {
                'integer' => $schema->integer(),
                'number'  => $schema->number(),
                'boolean' => $schema->boolean(),
                default   => $schema->string(),
            };

            if (!empty($prop['description'])) {
                $field = $field->description($prop['description']);
            }

            if (!empty($prop['enum'])) {
                $field = $field->enum($prop['enum']);
            }

            if (in_array($name, $required, true)) {
                $field = $field->required();
            }

            $result[$name] = $field;
        }

        return $result;
    }
}
