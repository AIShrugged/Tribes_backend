<?php

namespace App\Services\Agent\Tools;

use App\Enums\InsightContextType;
use App\Models\InsightShortTerm;
use App\Models\User;

class UpdateMemoryTool implements ToolInterface
{
    public function __construct(private readonly User $user)
    {
    }

    public function getName(): string
    {
        return 'update_memory';
    }

    public function getDescription(): string
    {
        $contextTypes = collect(InsightContextType::cases())
            ->map(fn ($case) => $case->value)
            ->join(', ');

        return "Update your memory about this user. Choose appropriate context_type ({$contextTypes}). Use this to save important information from the conversation. The text should be written as notes to yourself.";
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'context_type' => [
                    'type'        => 'string',
                    'enum'        => InsightContextType::values(),
                    'description' => 'Type of context: current_projects (active work/tasks), recent_decisions (decisions made), emotional_state (mood/feelings), general_knowledge (general notes about user)',
                ],
                'memory_text' => [
                    'type'        => 'string',
                    'description' => 'The memory text. Write as natural instructions to yourself.',
                ],
            ],
            'required' => ['context_type', 'memory_text'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters  = $parameters ?? [];
        $contextType = $parameters['context_type'] ?? '';
        $memoryText  = $parameters['memory_text'] ?? '';

        if (empty($contextType) || empty($memoryText)) {
            return ['success' => false, 'error' => 'context_type and memory_text are required'];
        }

        $validContextType = InsightContextType::tryFrom($contextType);
        if (! $validContextType) {
            return [
                'success' => false,
                'error'   => 'Invalid context_type. Must be one of: ' . implode(', ', InsightContextType::values()),
            ];
        }

        $email = $this->user->email;
        if (! $email) {
            return ['success' => false, 'error' => 'User has no email. Cannot save memory.'];
        }

        try {
            InsightShortTerm::updateOrCreate(
                ['email' => $email, 'context_type' => $validContextType],
                ['content' => ['text' => $memoryText], 'expires_at' => now()->addMonths(3)]
            );

            return ['success' => true, 'message' => "Memory updated successfully (context: {$validContextType->value})"];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
