<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\TeamNotificationSetting;
use App\Models\User;

class TeamNotificationSettingPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->isOrganizationManager($team->organization);
    }

    public function create(User $user, Team $team): bool
    {
        return $user->isOrganizationManager($team->organization);
    }

    public function update(User $user, TeamNotificationSetting $setting): bool
    {
        return $user->isOrganizationManager($setting->team->organization);
    }

    public function destroy(User $user, TeamNotificationSetting $setting): bool
    {
        return $user->isOrganizationManager($setting->team->organization);
    }
}
