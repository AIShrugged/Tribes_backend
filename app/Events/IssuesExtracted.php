<?php

namespace App\Events;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class IssuesExtracted
{
    use Dispatchable, SerializesModels;

    /**
     * @param Collection<int, \App\Models\Issue> $issues
     */
    public function __construct(
        public Collection $issues,
        public Team $team,
        public User $user,
    ) {}
}
