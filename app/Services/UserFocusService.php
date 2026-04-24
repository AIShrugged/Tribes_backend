<?php

namespace App\Services;

use App\Models\InsightShortTerm;
use App\Models\Profile;

class UserFocusService
{
    public function setFocus(Profile $profile, string $focusText, ?string $deadline = null): InsightShortTerm
    {
        $expiresAt = $deadline
            ? \Carbon\Carbon::parse($deadline)->endOfDay()
            : now()->addDays(14);

        return InsightShortTerm::setFocus($profile->id, $focusText, $deadline, $expiresAt);
    }

    public function getFocus(Profile $profile): ?InsightShortTerm
    {
        return InsightShortTerm::forFocus($profile->id)
            ->active()
            ->latest('updated_at')
            ->first();
    }

    public function clearFocus(Profile $profile): void
    {
        InsightShortTerm::forFocus($profile->id)->delete();
    }
}