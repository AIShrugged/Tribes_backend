<?php

namespace App\Services\Commands;

/**
 * Outcome of a command, used both as the tool result and the audit record.
 */
class CommandResult
{
    /**
     * @param  array<string, mixed>  $data           Result returned to the agent.
     * @param  array<string, mixed>  $snapshot       Prior state (for audit / debugging).
     * @param  array<string, mixed>|null  $inversePayload  Serializable reverse op, or null if irreversible.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $targetType,
        public readonly int|string|null $targetId,
        public readonly array $data,
        public readonly array $snapshot = [],
        public readonly ?array $inversePayload = null,
        public readonly string $summary = '',
    ) {}
}
