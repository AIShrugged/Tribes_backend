<?php

namespace App\Services\Agent\Tools;

use App\Models\Team;
use App\Models\User;
use App\Services\Agent\Tools\Concerns\InteractsWithMcpTenant;

class GetTeamMembersTool extends AbstractAgentTool
{
    use InteractsWithMcpTenant;

    public function __construct(
        private readonly ?User $user = null,
        private readonly ?int $organizationId = null,
        private readonly ?int $teamId = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'get_team_members';
    }

    public function getDescription(): string
    {
        return 'Get all members of a specific team. Returns basic user info: names, emails, and user IDs. For each member\'s role, responsibilities, or what they actually do — call get_user_insights with their profile_id.';
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

        $user = $this->currentUser($this->user);
        if (! $user) {
            return ['success' => false, 'error' => 'Not authenticated.'];
        }

        $orgScope = $this->organizationId ?? $this->currentOrganizationId($user);

        $query = Team::query()->with(['users', 'organization']);

        if ($orgScope !== null) {
            $query->where('organization_id', $orgScope);
        }

        if ($this->teamId !== null) {
            $query->where('id', $this->teamId);
        }

        if ($teamId) {
            $team = $query->find($teamId);
        } else {
            $team = $query->where('name', 'ilike', "%{$teamName}%")->first();
        }

        if (! $team) {
            $suggestions = Team::query()
                ->when(
                    $orgScope !== null,
                    fn ($q) => $q->where('organization_id', $orgScope)
                )
                ->where('name', 'ilike', '%' . mb_substr($teamName ?? '', 0, 3) . '%')
                ->limit(5)
                ->pluck('name')
                ->toArray();

            return [
                'success'     => false,
                'error'       => 'Team not found in the current organization scope' . ($teamName ? " for name '{$teamName}'" : ''),
                'suggestions' => $suggestions,
            ];
        }

        if (! $user->isTeamMember($team) && ! $user->isOrganizationManager($team->organization_id)) {
            return [
                'success' => false,
                'error' => 'Team not found or access denied',
            ];
        }

        $memberIds = $team->users->pluck('id');
        $profileMap = \App\Models\Profile::whereIn('user_id', $memberIds)
            ->orderBy('id')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($g) => $g->first()->id);

        $members = $team->users->map(fn ($user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'profile_id' => $profileMap[$user->id] ?? null,
        ])->toArray();

        return [
            'success' => true,
            '_hint' => 'profile_id is available for each member. To get roles, responsibilities, or what each person does — call get_user_insights(profile_id) for each member.',
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
