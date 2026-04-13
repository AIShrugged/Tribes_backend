<?php

namespace App\Services\Paperclip\Payloads;

use App\Services\Paperclip\PaperclipPayloadInterface;

class AgentRunPayload implements PaperclipPayloadInterface
{
    public function __construct(
        public readonly string $issueId,
        public readonly ?string $output = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function fromArray(array $data): static
    {
        $issueId = $data['issueId'] ?? null;

        if (! $issueId) {
            throw new \InvalidArgumentException('Paperclip webhook payload missing required field: issueId');
        }

        return new static(
            issueId: $issueId,
            output: $data['output'] ?? $data['planDocument'] ?? null,
            errorMessage: $data['error'] ?? $data['reason'] ?? null,
        );
    }
}
