<?php

namespace App\Policies;

use App\Models\Profile;
use App\Models\User;

class ProfilePolicy
{
    /**
     * Determine whether the user can view insight data about a Profile.
     *
     * Access is granted if:
     *  - The Profile belongs to the requesting user directly, OR
     *  - The Profile participated in a CalendarEvent owned by the requesting user.
     */
    public function view(User $user, Profile $profile): bool
    {
        if ($profile->user_id === $user->id) {
            return true;
        }

        return $profile->calendarEvents()
            ->whereHas('source', fn ($q) => $q->where('user_id', $user->id))
            ->exists();
    }

    /**
     * Determine whether the user can delete all insight data for a Profile.
     *
     * Only the user who owns the Profile can request a data deletion (GDPR forget).
     */
    public function forget(User $user, Profile $profile): bool
    {
        return $profile->user_id === $user->id;
    }

    /**
     * Determine whether the user can unlink (detach) a Profile from their account.
     *
     * Only the user who owns the Profile can unlink it.
     */
    public function unlink(User $user, Profile $profile): bool
    {
        return $profile->user_id === $user->id;
    }
}
