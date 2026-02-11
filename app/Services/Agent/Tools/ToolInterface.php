<?php

namespace App\Services\Agent\Tools;

interface ToolInterface
{
    /**
     * Get the name of the tool (used by LLM to call it)
     */
    public function getName(): string;

    /**
     * Get a description of what the tool does
     */
    public function getDescription(): string;

    /**
     * Get the parameters schema for the tool
     * Returns OpenAI function calling format
     */
    public function getParameters(): array;

    /**
     * Execute the tool with given parameters
     */
    public function execute(?array $parameters): mixed;
}