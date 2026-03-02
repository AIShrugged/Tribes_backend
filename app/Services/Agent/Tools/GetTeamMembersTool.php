<?php

namespace App\Services\Agent\Tools;

use App\Models\Team;

class GetTeamMembersTool implements ToolInterface
{
    public function getName(): string
    {
        return 'get_team_members';
    }

    public function getDescription(): string
    {
        return 'Get all members of a specific team. Returns user information for all team members including their names, emails, and roles.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the team to fetch members from',
                ],
                'team_name' => [
                    'type' => 'string',
                    'description' => 'The name of the team to fetch members from (alternative to team_id)',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        // Handle null parameters
        $parameters = $parameters ?? [];

        $teamId = $parameters['team_id'] ?? null;
        $teamName = $parameters['team_name'] ?? null;

        if (! $teamId && ! $teamName) {
            return [
                'success' => false,
                'error' => 'Either team_id or team_name must be provided',
            ];
        }

        $query = Team::query()->with(['users', 'organization']);

        if ($teamId) {
            $team = $query->find($teamId);
        } else {
            $team = $query->where('name', 'ilike', "%{$teamName}%")->first();
        }

        if (! $team) {
            $suggestions = Team::where('name', 'ilike', '%' . mb_substr($teamName ?? '', 0, 3) . '%')
                ->limit(5)
                ->pluck('name')
                ->toArray();

            return [
                'success'     => false,
                'error'       => "Team not found" . ($teamName ? " for name '{$teamName}'" : ''),
                'suggestions' => $suggestions,
            ];
        }

        $members = $team->users->map(fn ($user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ])->toArray();

        return [
            'success' => true,
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'organization' => $team->organization ? [
                    'id' => $team->organization->id,
                    'name' => $team->organization->name,
                ] : null,
                'member_count' => count($members),
            ],
            'members' => $members,
        ];
    }
}