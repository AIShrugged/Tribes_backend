<?php

namespace App\Services\Agent\Tools;

use App\Enums\InsightContextType;
use App\Models\InsightShortTerm;
use App\Models\TelegramUser;

class UpdateMemoryTool implements ToolInterface
{
    private int $telegramUserId;

    public function __construct(int $telegramUserId)
    {
        $this->telegramUserId = $telegramUserId;
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
            'type' => 'object',
            'properties' => [
                'context_type' => [
                    'type' => 'string',
                    'enum' => InsightContextType::values(),
                    'description' => 'Type of context: current_projects (active work/tasks), recent_decisions (decisions made), emotional_state (mood/feelings), general_knowledge (general notes about user)',
                ],
                'memory_text' => [
                    'type' => 'string',
                    'description' => 'The memory text. Write as natural instructions to yourself.',
                ],
            ],
            'required' => ['context_type', 'memory_text'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        // Handle null parameters
        $parameters = $parameters ?? [];

        $contextType = $parameters['context_type'] ?? '';
        $memoryText = $parameters['memory_text'] ?? '';

        if (empty($contextType) || empty($memoryText)) {
            return [
                'success' => false,
                'error' => 'context_type and memory_text are required',
            ];
        }

        // Validate context_type
        $validContextType = InsightContextType::tryFrom($contextType);
        if (! $validContextType) {
            return [
                'success' => false,
                'error' => 'Invalid context_type. Must be one of: '.implode(', ', InsightContextType::values()),
            ];
        }

        try {
            $telegramUser = TelegramUser::findOrFail($this->telegramUserId);

            // Check if user has linked account
            $email = $telegramUser->user?->email;
            if (! $email) {
                return [
                    'success' => false,
                    'error' => 'No user account linked. Cannot save memory.',
                ];
            }

            // Save to InsightShortTerm
            InsightShortTerm::updateOrCreate(
                [
                    'email' => $email,
                    'context_type' => $validContextType,
                ],
                [
                    'content' => ['text' => $memoryText],
                    'expires_at' => now()->addMonths(3),
                ]
            );

            return [
                'success' => true,
                'message' => "Memory updated successfully (context: {$validContextType->value})",
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
