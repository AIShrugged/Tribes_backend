<?php

namespace App\Services\Dashboard;

use App\Domain\DTO\Dashboard\TeamStatsDTO;
use App\Models\User;
use Illuminate\Support\Collection;

class TeamStatsService
{
    public function getStats(User $user): TeamStatsDTO
    {
        $teams = $this->loadTeams($user);

        return new TeamStatsDTO(
            total: $teams->count(),
            list:  $teams,
        );
    }

    private function loadTeams(User $user): Collection
    {
        return $user->teams()
            ->withCount('users')
            ->get()
            ->map(fn ($team) => [
                'id'            => $team->id,
                'name'          => $team->name,
                'members_count' => $team->users_count,
            ]);
    }
}
