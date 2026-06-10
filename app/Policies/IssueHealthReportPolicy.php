<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

class IssueHealthReportPolicy
{
    public function view(User $user, Team $team): bool
    {
        return $user->isTeamMember($team);
    }
}
