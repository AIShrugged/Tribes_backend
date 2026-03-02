<?php

namespace App\Services\Agent\Tools;

use App\Models\Participant;
use App\Models\Profile;
use App\Models\User;

class GetUserInfoTool implements ToolInterface
{
    public function getName(): string
    {
        return 'get_user_info';
    }

    public function getDescription(): string
    {
        return 'Find and get detailed information about a user by their ID, email, or full/partial name. Returns user name, email, profiles (with profile_id per channel — use profile_id with get_extracted_facts), organizations, teams, and role information. Name search may return multiple matches.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the user (exact match)',
                ],
                'email' => [
                    'type' => 'string',
                    'description' => 'The email of the user (exact match)',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Full or partial name of the user (case-insensitive, partial match). May return multiple users if name is not unique.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $userId = $parameters['user_id'] ?? null;
        $email  = $parameters['email'] ?? null;
        $name   = $parameters['name'] ?? null;

        if (!$userId && !$email && !$name) {
            return [
                'success' => false,
                'error' => 'One of user_id, email, or name must be provided',
            ];
        }

        $query = User::query()->with(['organizations', 'teams', 'profiles.channel']);

        // Exact lookups — return single user
        if ($userId) {
            $user = $query->find($userId);

            return $user
                ? ['success' => true, 'user' => $this->formatUser($user)]
                : ['success' => false, 'error' => 'User not found'];
        }

        if ($email) {
            $user = $query->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->first();

            return $user
                ? ['success' => true, 'user' => $this->formatUser($user)]
                : ['success' => false, 'error' => 'User not found'];
        }

        // Name search — try users first.
        // Use PostgreSQL word-boundary regex (\y) to avoid patronymic false positives
        // e.g. "Константин" must NOT match "Сафонов Глеб Константинович".
        $escaped = preg_quote($name, '/');
        $users = $query->whereRaw('name ~* ?', ['\\y' . $escaped . '\\y'])->limit(10)->get();

        if ($users->isNotEmpty()) {
            if ($users->count() === 1) {
                return ['success' => true, 'user' => $this->formatUser($users->first())];
            }

            return [
                'success'          => true,
                'multiple_matches' => true,
                'message'          => "Found {$users->count()} users matching '{$name}'. Clarify which one or use email/user_id for exact lookup.",
                'users'            => $users->map(fn ($u) => [
                    'id'    => $u->id,
                    'name'  => $u->name,
                    'email' => $u->email,
                ])->toArray(),
            ];
        }

        // Fallback: search participants by name → resolve profiles
        $profileIds = Participant::where('name', 'ilike', '%' . $name . '%')
            ->whereNotNull('profile_id')
            ->pluck('profile_id')
            ->unique()
            ->values();

        if ($profileIds->isEmpty()) {
            return [
                'success' => false,
                'error'   => "No users found matching name '{$name}'",
            ];
        }

        $profiles = Profile::with('channel')->whereIn('id', $profileIds)->get();

        if ($profiles->count() === 1) {
            return ['success' => true, 'user' => $this->formatProfile($profiles->first(), $name)];
        }

        return [
            'success'          => true,
            'multiple_matches' => true,
            'message'          => "Found {$profiles->count()} profiles matching '{$name}'. Use profile_id or email for exact lookup.",
            'users'            => $profiles->map(fn ($p) => [
                'id'         => null,
                'profile_id' => $p->id,
                'email'      => $p->channel_identifier,
            ])->toArray(),
        ];
    }

    private function formatProfile(Profile $profile, string $name): array
    {
        return [
            'id'            => null,
            'name'          => $name,
            'email'         => $profile->channel_identifier,
            'profiles'      => [[
                'profile_id'         => $profile->id,
                'channel'            => $profile->channel?->name,
                'channel_identifier' => $profile->channel_identifier,
            ]],
            'organizations' => [],
            'teams'         => [],
            'note'          => 'Profile found via meeting participants. No linked user account.',
        ];
    }

    private function formatUser(User $user): array
    {
        $profiles = $user->profiles;

        // Fallback: if no profiles linked via user_id, search by email
        if ($profiles->isEmpty() && $user->email) {
            $byEmail = Profile::with('channel')->where('channel_identifier', $user->email)->first();
            if ($byEmail) {
                $profiles = collect([$byEmail]);
            }
        }

        return [
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
        ];
    }
}