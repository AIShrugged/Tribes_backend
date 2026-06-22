<?php

namespace App\Services\Agent\Tools\Concerns;

use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Shared tenant-resolution and access-control layer for tools that may be
 * invoked over MCP by an external client (e.g. the "second brain" sidecar).
 *
 * Why this exists: laravel/mcp instantiates tools via the service container,
 * which does NOT inject the authenticated user (and builds an *empty* User for
 * a `?User $user = null` constructor param). So tools must NOT rely on a
 * constructor-injected user over MCP — they must resolve the acting user from
 * the Sanctum guard (auth:sanctum populates Auth::user()) and scope every
 * lookup to that user's organization(s). Used as defense-in-depth on the
 * internal agent path too (where a real user IS injected and is preferred).
 */
trait InteractsWithMcpTenant
{
    /**
     * Resolve the acting user. Prefers a real, persisted injected user
     * (internal agent path); otherwise falls back to the authenticated Sanctum
     * user, then to the bearer token on the current request (MCP path).
     */
    protected function currentUser(?User $injected = null): ?User
    {
        if ($injected instanceof User && $injected->exists) {
            return $injected;
        }

        $user = Auth::user();
        if ($user instanceof User) {
            return $user;
        }

        $token = request()?->bearerToken();
        if ($token) {
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken?->tokenable instanceof User) {
                return $accessToken->tokenable;
            }
        }

        return null;
    }

    /**
     * Whether the current call is arriving over the MCP HTTP endpoint (external
     * client), as opposed to the internal agent path. Used to apply stricter
     * scoping for empty-constructor tools that can't distinguish via an injected
     * user. Returns false outside an HTTP request (queue/console).
     */
    protected function isMcpRequest(): bool
    {
        return request()?->is('mcp', 'mcp/*') ?? false;
    }

    /**
     * IDs of all organizations the acting user belongs to.
     *
     * @return array<int, int>
     */
    protected function currentOrganizationIds(?User $injected = null): array
    {
        $user = $this->currentUser($injected);

        if (! $user) {
            return [];
        }

        return $user->organizations()->pluck('organizations.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The acting user's single organization id, or null when ambiguous
     * (zero or multiple orgs). A per-org service user resolves unambiguously.
     */
    protected function currentOrganizationId(?User $injected = null): ?int
    {
        $ids = $this->currentOrganizationIds($injected);

        return count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * Return the issue only if the acting user may see it, else null.
     */
    protected function assertCanAccessIssue(int $issueId, ?User $injected = null): ?Issue
    {
        $user = $this->currentUser($injected);

        if (! $user) {
            return null;
        }

        return Issue::query()->withoutTrashed()->visibleTo($user)->find($issueId);
    }

    /**
     * Return the calendar event only if it is visible to one of the acting
     * user's organizations, else null.
     */
    protected function assertCanAccessMeeting(int $calendarEventId, ?User $injected = null): ?CalendarEvent
    {
        $orgIds = $this->currentOrganizationIds($injected);

        if (empty($orgIds)) {
            return null;
        }

        return CalendarEvent::query()
            ->whereKey($calendarEventId)
            ->where(function (Builder $q) use ($orgIds): void {
                foreach ($orgIds as $orgId) {
                    $q->orWhere(fn (Builder $inner) => $inner->visibleToOrganization($orgId));
                }
            })
            ->first();
    }

    /**
     * Whether the acting user may read insights/facts about the given profile
     * (the profile belongs to a person who shares one of the user's orgs).
     */
    protected function assertCanAccessProfile(int $profileId, ?User $injected = null): bool
    {
        $user = $this->currentUser($injected);

        if (! $user) {
            return false;
        }

        $profile = Profile::query()->find($profileId);

        if (! $profile) {
            return false;
        }

        if ((int) $profile->user_id === (int) $user->id) {
            return true;
        }

        if (! $profile->user_id) {
            return false;
        }

        $orgIds = $this->currentOrganizationIds($injected);

        if (empty($orgIds)) {
            return false;
        }

        return User::query()
            ->whereKey($profile->user_id)
            ->whereHas('organizations', fn (Builder $q) => $q->whereIn('organizations.id', $orgIds))
            ->exists();
    }
}
