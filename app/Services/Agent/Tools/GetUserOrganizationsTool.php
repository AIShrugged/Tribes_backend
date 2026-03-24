<?php

namespace App\Services\Agent\Tools;

use App\Enums\UserRole;
use App\Models\User;

class GetUserOrganizationsTool implements ToolInterface
{
    public function __construct(
        private readonly User $user,
    ) {
    }

    public function getName(): string
    {
        return 'get_user_organizations';
    }

    public function getDescription(): string
    {
        return 'Returns the list of organizations where the current user is a manager. '
            . 'Use before saving a methodology to let the user choose which organization to assign it to.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $organizations = $this->user->organizations()
            ->wherePivot('role', UserRole::MANAGER->value)
            ->get(['organizations.id', 'organizations.name']);

        return [
            'success'       => true,
            'organizations' => $organizations->map(fn ($org) => [
                'id'   => $org->id,
                'name' => $org->name,
            ])->values()->toArray(),
        ];
    }
}
