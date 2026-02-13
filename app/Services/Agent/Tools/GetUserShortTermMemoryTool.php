<?php

namespace App\Services\Agent\Tools;

use App\Models\User;
use App\Services\Insight\InsightRetrievalService;

class GetUserShortTermMemoryTool implements ToolInterface
{
    public function getName(): string
    {
        return 'get_user_short_term_memory';
    }

    public function getDescription(): string
    {
        return 'Get short-term context about a user. Returns temporary information like current projects, recent decisions, emotional state, and general knowledge. This is recent context that expires over time.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the user',
                ],
                'email' => [
                    'type' => 'string',
                    'description' => 'The email of the user',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $userId = $parameters['user_id'] ?? null;
        $email = $parameters['email'] ?? null;

        if (! $userId && ! $email) {
            return [
                'success' => false,
                'error' => 'Either user_id or email must be provided',
            ];
        }

        if ($userId) {
            $user = User::find($userId);
        } else {
            $user = User::where('email', $email)->first();
        }

        if (! $user) {
            return [
                'success' => false,
                'error' => 'User not found',
            ];
        }

        $retrievalService = app(InsightRetrievalService::class);
        $shortTerm = $retrievalService->getShortTermContext($user->email);

        return [
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'short_term_context' => $shortTerm,
        ];
    }
}