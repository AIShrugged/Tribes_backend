<?php

namespace App\Services;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolves which teams of a user should drive a given operation in a given org.
 *
 * After the introduction of default teams (is_default=true, contains every
 * org member), naive `$user->teams()->where('organization_id', $orgId)->get()`
 * iteration is wrong: it processes the default team alongside real teams,
 * which double-fires notifications and/or buries real-team selection inside
 * a synthetic catch-all team.
 *
 * Two distinct contexts have inverse semantics:
 *   - PipelineTrigger: default is a VALID fallback when no real teams exist
 *     (otherwise orgs without real teams produce no followups at all — the
 *     original bug the default-team feature exists to fix).
 *   - OutboundNotification: default is OPT-IN — it must have explicit
 *     TeamNotificationSetting rows to participate, otherwise users with TG
 *     configured on both default and real team receive duplicate sends.
 *
 * See plan docs/plans/2026-05-28-feat-default-team-per-organization-plan.md
 * (Phase 4b) for full rationale.
 */
class UserTeamsResolver
{
    /**
     * Real teams in the org. Falls back to default team when no real team exists.
     *
     * Use from listeners that DRIVE downstream pipeline work — extraction,
     * analysis, dispatch of next-stage jobs. Default team is a valid trigger
     * target because orgs without real teams must still get followups generated.
     *
     * @return Collection<int, Team>
     */
    public function forPipelineTrigger(User $user, ?int $orgId): Collection
    {
        $teams = $this->teamsScopedToOrg($user, $orgId);

        if ($teams->isEmpty()) {
            return collect();
        }

        $real = $teams->reject(fn (Team $t) => $t->isDefault())->values();

        return $real->isNotEmpty()
            ? $real
            : $teams->filter(fn (Team $t) => $t->isDefault())->values();
    }

    /**
     * Real teams in the org, plus default team only when it has explicit
     * notification settings configured.
     *
     * Use from listeners that SEND outbound messages (Telegram/Slack/email).
     * Default team is opt-in here — otherwise users with TG configured on
     * both default and a real team would receive duplicate sends.
     *
     * @return Collection<int, Team>
     */
    public function forOutboundNotification(User $user, ?int $orgId): Collection
    {
        $query = $user->teams();

        if ($orgId !== null) {
            $query->where('organization_id', $orgId);
        }

        return $query
            ->where(function ($q) {
                $q->where('teams.is_default', false)
                    ->orWhereHas('notificationSettings');
            })
            ->get();
    }

    /**
     * @return Collection<int, Team>
     */
    private function teamsScopedToOrg(User $user, ?int $orgId): Collection
    {
        return $orgId !== null
            ? $user->teams()->where('organization_id', $orgId)->get()
            : $user->teams()->get();
    }
}
