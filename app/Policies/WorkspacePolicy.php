<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAccessService;

class WorkspacePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return app(WorkspaceAccessService::class)->abilitiesForUser($user, $workspace)['read'] === true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return app(WorkspaceAccessService::class)->abilitiesForUser($user, $workspace)['admin'] === true;
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return app(WorkspaceAccessService::class)->abilitiesForUser($user, $workspace)['admin'] === true;
    }
}
