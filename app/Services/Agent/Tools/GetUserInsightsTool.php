<?php

namespace App\Services\Agent\Tools;

use App\Models\Channel;
use App\Models\Profile;
use App\Models\User;
use App\Services\Insight\InsightRetrievalService;

class GetUserInsightsTool extends AbstractAgentTool
{
    public function getName(): string
    {
        return 'get_user_insights';
    }

    public function getDescription(): string
    {
        return 'Get full insight profile about a user. Includes their role and function in the team/organization, areas of responsibility, psychological profile, communication style, work patterns, strengths, development areas, goals/motivations, short-term context, and relationships with other people. Use this when asked about what a person does, their role, position, or responsibilities in the team. Always call get_user_info first to obtain profile_id, then pass it here.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile_id' => [
                    'type' => 'integer',
                    'description' => 'PREFERRED: The profile_id from profiles[].profile_id returned by get_user_info. Always use this when available.',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'The user_id (id field) returned by get_user_info. Use only if profile_id is not available.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $userId    = $parameters['user_id'] ?? null;
        $email     = $this->normalizeEmailInput($parameters['email'] ?? null);
        $profileId = $parameters['profile_id'] ?? null;

        if (! $userId && ! $email && ! $profileId) {
            return [
                'success' => false,
                'error' => 'One of user_id, email, or profile_id must be provided',
            ];
        }

        // Direct profile_id lookup — for users without an account
        if ($profileId) {
            $profile = Profile::find($profileId);

            if (! $profile) {
                return ['success' => false, 'error' => 'Profile not found'];
            }
        } else {
            if ($userId) {
                $user = User::find($userId);
            } else {
                $user = User::whereRaw("replace(lower(trim(email)), ' ', '') = ?", [$email])->first();
            }

            if (! $user) {
                return ['success' => false, 'error' => 'User not found'];
            }

            $profile = $this->resolveProfile($user);
        }

        if (! $profile) {
            return [
                'success' => true,
                'data' => null,
                'message' => 'No insight profile found for this user',
            ];
        }

        $retrievalService = app(InsightRetrievalService::class);
        $data = $retrievalService->getFullProfile($profile->id);

        return [
            'success' => true,
            'data' => $data,
        ];
    }

    /**
     * Resolve a Profile from a User.
     * Prefers the profile linked via user_id; falls back to google_calendar channel (email).
     */
    private function resolveProfile(User $user): ?Profile
    {
        $profile = Profile::where('user_id', $user->id)->first();

        if (! $profile) {
            $gcChannelId = Channel::idFor('google_calendar');
            if ($gcChannelId) {
                $profile = Profile::where('channel_id', $gcChannelId)
                    ->where('channel_identifier', $user->email)
                    ->first();
            }
        }

        return $profile;
    }

    private function normalizeEmailInput(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));
        $normalized = preg_replace('/\s+/', '', $normalized) ?? $normalized;

        return $normalized !== '' ? $normalized : null;
    }
}
