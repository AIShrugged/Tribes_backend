<?php

namespace App\Services\Agent\Tools;

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
        return 'Update your long-term memory about this user. Use this to save important information from the conversation. You should pass the COMPLETE updated memory text that includes both old information (from context) and new information. The text should be written as notes to yourself about how to interact with this user.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'memory_text' => [
                    'type' => 'string',
                    'description' => 'The complete updated memory text. Should include both previous context and new information. Write as natural instructions to yourself.',
                ],
            ],
            'required' => ['memory_text'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        // Handle null parameters
        $parameters = $parameters ?? [];

        $memoryText = $parameters['memory_text'] ?? '';

        if (empty($memoryText)) {
            return [
                'success' => false,
                'error' => 'memory_text is required',
            ];
        }

        try {
            $telegramUser = TelegramUser::where('telegram_id', $this->telegramUserId)->firstOrFail();
            $telegramUser->updateMemory($memoryText);

            return [
                'success' => true,
                'message' => 'Memory updated successfully',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}