<?php

namespace App\Services\Agent\Tools;

use App\Models\User;
use App\Services\Insight\InsightRetrievalService;

class GetRelationshipInsightTool implements ToolInterface
{
    public function getName(): string
    {
        return 'get_relationship_insight';
    }

    public function getDescription(): string
    {
        return 'Get relationship dynamics between two people. Returns the type of relationship (collaborative, conflicting, hierarchical, neutral), interaction dynamics, and interaction count.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'email_a' => [
                    'type' => 'string',
                    'description' => 'Email of the first person',
                ],
                'email_b' => [
                    'type' => 'string',
                    'description' => 'Email of the second person',
                ],
                'user_id_a' => [
                    'type' => 'integer',
                    'description' => 'User ID of the first person (alternative to email_a)',
                ],
                'user_id_b' => [
                    'type' => 'integer',
                    'description' => 'User ID of the second person (alternative to email_b)',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $emailA = $parameters['email_a'] ?? null;
        $emailB = $parameters['email_b'] ?? null;
        $userIdA = $parameters['user_id_a'] ?? null;
        $userIdB = $parameters['user_id_b'] ?? null;

        // Resolve emails from user IDs if needed
        if (! $emailA && $userIdA) {
            $user = User::find($userIdA);
            $emailA = $user?->email;
        }
        if (! $emailB && $userIdB) {
            $user = User::find($userIdB);
            $emailB = $user?->email;
        }

        if (! $emailA || ! $emailB) {
            return [
                'success' => false,
                'error' => 'Two people must be specified (via email_a/email_b or user_id_a/user_id_b)',
            ];
        }

        $retrievalService = app(InsightRetrievalService::class);
        $relationship = $retrievalService->getRelationship($emailA, $emailB);

        if (! $relationship) {
            return [
                'success' => true,
                'data' => null,
                'message' => 'No relationship data found between these two people',
            ];
        }

        return [
            'success' => true,
            'data' => $relationship,
        ];
    }
}