<?php

namespace App\Services\Agent\Tools;

use App\Models\User;
use App\Services\Insight\InsightRetrievalService;

class GetUserInsightsTool implements ToolInterface
{
    public function getName(): string
    {
        return 'get_user_insights';
    }

    public function getDescription(): string
    {
        return 'Get full insight profile about a user. Returns long-term psychological profile, communication style, work patterns, strengths, development areas, goals/motivations, active short-term context, and relationships with other people.';
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
        $profile = $retrievalService->getFullProfile($user->email);

        return [
            'success' => true,
            'data' => $profile,
        ];
    }
}