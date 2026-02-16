<?php

namespace App\Services\Agent\Tools;

use App\Models\Channel;
use App\Models\Profile;
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

        $emailA  = $parameters['email_a'] ?? null;
        $emailB  = $parameters['email_b'] ?? null;
        $userIdA = $parameters['user_id_a'] ?? null;
        $userIdB = $parameters['user_id_b'] ?? null;

        $profileIdA = $this->resolveProfileId($emailA, $userIdA);
        $profileIdB = $this->resolveProfileId($emailB, $userIdB);

        if (! $profileIdA || ! $profileIdB) {
            return [
                'success' => false,
                'error' => 'Could not resolve insight profiles for both people. Provide valid email_a/email_b or user_id_a/user_id_b.',
            ];
        }

        $retrievalService = app(InsightRetrievalService::class);
        $relationship = $retrievalService->getRelationship($profileIdA, $profileIdB);

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

    /**
     * Resolve a profile_id from optional email or user_id.
     * Prefers user_id → linked profile; falls back to google_calendar channel (email).
     */
    private function resolveProfileId(?string $email, ?int $userId): ?int
    {
        if ($userId) {
            $user = User::find($userId);
            if ($user) {
                $profile = Profile::where('user_id', $user->id)->first();
                if ($profile) {
                    return $profile->id;
                }
                // Fallback: match by email via google_calendar channel
                $email = $email ?? $user->email;
            }
        }

        if ($email) {
            $gcChannelId = Channel::idFor('google_calendar');
            if ($gcChannelId) {
                $profile = Profile::where('channel_id', $gcChannelId)
                    ->where('channel_identifier', $email)
                    ->first();
                if ($profile) {
                    return $profile->id;
                }
            }
        }

        return null;
    }
}
