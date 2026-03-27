<?php

namespace App\Services\Recall;

use App\Models\Profile;
use App\Models\User;

class CreatorResolverService
{
    public function resolve(?string $email): ?int
    {
        if (!$email) {
            return null;
        }

        $user = User::where('email', $email)->first();
        if ($user) {
            return $user->id;
        }

        $profile = Profile::where('channel_identifier', $email)
            ->whereNotNull('user_id')
            ->first();

        return $profile?->user_id;
    }
}
