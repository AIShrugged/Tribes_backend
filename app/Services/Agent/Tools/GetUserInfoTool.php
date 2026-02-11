<?php

namespace App\Services\Agent\Tools;

use App\Models\User;

class GetUserInfoTool implements ToolInterface
{
    public function getName(): string
    {
        return 'get_user_info';
    }

    public function getDescription(): string
    {
        return 'Get detailed information about a user by their ID or email. Returns user profile, organization, teams, and role information.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the user to fetch information about',
                ],
                'email' => [
                    'type' => 'string',
                    'description' => 'The email of the user to fetch information about',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        // Handle null parameters
        $parameters = $parameters ?? [];

        $userId = $parameters['user_id'] ?? null;
        $email = $parameters['email'] ?? null;

        if (!$userId && !$email) {
            return [
                'success' => false,
                'error' => 'Either user_id or email must be provided',
            ];
        }

        $query = User::query()->with(['organizations', 'teams']);

        if ($userId) {
            $user = $query->find($userId);
        } else {
            $user = $query->where('email', $email)->first();
        }

        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found',
            ];
        }

        return [
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'organizations' => $user->organizations->map(fn($org) => [
                    'id' => $org->id,
                    'name' => $org->name,
                    'role' => $org->pivot->role ?? null,
                ])->toArray(),
                'teams' => $user->teams->map(fn($team) => [
                    'id' => $team->id,
                    'name' => $team->name,
                ])->toArray(),
            ],
        ];
    }
}