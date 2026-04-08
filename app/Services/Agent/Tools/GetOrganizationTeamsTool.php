<?php

namespace App\Services\Agent\Tools;

use App\Models\Team;
use App\Models\User;

class GetOrganizationTeamsTool implements ToolInterface
{
    public function __construct(
        private readonly User $user,
    ) {
    }

    public function getName(): string
    {
        return 'get_organization_teams';
    }

    public function getDescription(): string
    {
        return 'Returns the list of teams in an organization. '
            . 'Use to let the user choose which teams to assign a methodology to. '
            . 'Only available if the user is a manager of the specified organization.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['organization_id'],
            'properties' => [
                'organization_id' => [
                    'type'        => 'integer',
                    'description' => 'The ID of the organization to list teams for.',
                ],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $organizationId = $parameters['organization_id'] ?? null;

        if (! $organizationId) {
            return [
                'success' => false,
                'error'   => 'Parameter `organization_id` is required.',
            ];
        }

        if (! $this->user->isOrganizationMember($organizationId)) {
            return [
                'success' => false,
                'error'   => 'You are not a member of this organization.',
            ];
        }

        $teams = Team::where('organization_id', $organizationId)
            ->get(['id', 'name', 'methodology_id']);

        return [
            'success' => true,
            'teams'   => $teams->map(fn ($team) => [
                'id'                    => $team->id,
                'name'                  => $team->name,
                'current_methodology_id' => $team->methodology_id,
            ])->values()->toArray(),
        ];
    }
}
