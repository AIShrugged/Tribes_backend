<?php

namespace App\Policies;

use App\Models\Followup;
use App\Models\Team;
use App\Models\User;

class FollowupPolicy
{
    /**
     * Determine whether the user can view any followups of the team.
     */
    public function viewAny(User $user, Team $team): bool
    {
        // Пользователь может видеть список followup'ов, если:
        // - Он участник команды ИЛИ менеджер организации
        return $user->isTeamMember($team);
    }

    /**
     * Determine whether the user can view the followup.
     */
    public function view(User $user, Followup $followup): bool
    {
        // Пользователь может видеть followup, если:
        // - Он участник команды followup'а ИЛИ менеджер организации
        return $user->isTeamMember($followup->team);
    }
}
