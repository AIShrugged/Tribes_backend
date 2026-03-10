<?php

namespace App\Services\Agent\Tools;

use App\Models\Profile;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class GetCurrentUserTool extends AbstractAgentTool
{
    /**
     * @param User|string|null $userOrToken  Pass a User instance (AgentService/Telegram context)
     *                                        or a Sanctum token string (MCP/HTTP context).
     *                                        When null, falls back to request()->bearerToken().
     */
    public function __construct(
        private readonly User|string|null $userOrToken = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'get_current_user';
    }

    public function getDescription(): string
    {
        return 'Get the full profile of the currently authenticated user (the person sending messages). Returns user_id, profile_id, name, email, organizations, and teams. Use this when you need the current user\'s IDs for other tool calls.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [],
            'required'   => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $user = $this->resolveUser();

        if (! $user) {
            return ['success' => false, 'error' => 'Could not resolve authenticated user'];
        }

        $user = User::with(['organizations', 'teams', 'profiles.channel'])->find($user->id);

        if (! $user) {
            return ['success' => false, 'error' => 'Current user not found'];
        }

        $profiles = $user->profiles;

        if ($profiles->isEmpty() && $user->email) {
            $byEmail = Profile::with('channel')->where('channel_identifier', $user->email)->first();
            if ($byEmail) {
                $profiles = collect([$byEmail]);
            }
        }

        return [
            'success' => true,
            'user'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'profiles' => $profiles->map(fn ($profile) => [
                    'profile_id'         => $profile->id,
                    'channel'            => $profile->channel?->name,
                    'channel_identifier' => $profile->channel_identifier,
                ])->toArray(),
                'organizations' => $user->organizations->map(fn ($org) => [
                    'id'   => $org->id,
                    'name' => $org->name,
                    'role' => $org->pivot->role ?? null,
                ])->toArray(),
                'teams' => $user->teams->map(fn ($team) => [
                    'id'   => $team->id,
                    'name' => $team->name,
                ])->toArray(),
            ],
        ];
    }

    private function resolveUser(): ?User
    {
        // Already have a User instance
        if ($this->userOrToken instanceof User) {
            return $this->userOrToken;
        }

        // Token string provided or fall back to bearer token from current request
        $token = is_string($this->userOrToken) ? $this->userOrToken : request()->bearerToken();

        if (! $token) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);

        return $accessToken?->tokenable instanceof User ? $accessToken->tokenable : null;
    }
}
