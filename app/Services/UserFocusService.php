<?php

namespace App\Services;

use App\Models\InsightShortTerm;
use App\Models\Issue;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Collection;

class UserFocusService
{
    public function setFocus(Profile $profile, string $focusText, ?string $deadline = null, ?array $issueIds = null): InsightShortTerm
    {
        $expiresAt = $deadline
            ? \Carbon\Carbon::parse($deadline)->endOfDay()
            : now()->addDays(14);

        $validIds = $issueIds !== null ? $this->validateIssueIds($profile, $issueIds) : null;

        return InsightShortTerm::setFocus($profile->id, $focusText, $deadline, $expiresAt, $validIds);
    }

    public function getFocus(Profile $profile): ?InsightShortTerm
    {
        return InsightShortTerm::forFocus($profile->id)
            ->active()
            ->first();
    }

    public function clearFocus(Profile $profile): void
    {
        InsightShortTerm::forFocus($profile->id)->delete();
    }

    /**
     * Resolve focused issues for a user.
     *
     * Order of preference:
     *  1. Explicit `issue_ids` snapshot stored at setFocus time → load by ID, preserve user-given order.
     *  2. Legacy / thematic focus without `issue_ids` → FTS over name+description.
     *  3. FTS empty → fallback to critical-priority open issues.
     *
     * @return Collection<int, Issue>
     */
    public function getFocusedIssues(User $user): Collection
    {
        $profile = Profile::where('user_id', $user->id)->first();
        if (! $profile) {
            return collect();
        }

        $focus = $this->getFocus($profile);
        if (! $focus) {
            return collect();
        }

        $explicitIds = $focus->content['issue_ids'] ?? null;
        if (is_array($explicitIds) && ! empty($explicitIds)) {
            $issues = Issue::query()
                ->whereIn('id', $explicitIds)
                ->whereNotIn('status', ['done', 'closed', 'cancelled'])
                ->where(function ($q) use ($user) {
                    $q->where('assignee_id', $user->id)->orWhere('user_id', $user->id);
                })
                ->with('assignee')
                ->get()
                ->keyBy('id');

            $ordered = collect();
            foreach ($explicitIds as $id) {
                if ($issues->has($id)) {
                    $ordered->push($issues->get($id));
                }
            }
            return $ordered;
        }

        $focusText = $focus->content['focus_text'] ?? '';
        if ($focusText !== '') {
            $matched = Issue::query()
                ->whereNotIn('status', ['done', 'closed', 'cancelled'])
                ->whereRaw(
                    "to_tsvector('russian', coalesce(name, '') || ' ' || coalesce(description, '')) @@ plainto_tsquery('russian', ?)",
                    [$focusText]
                )
                ->where(function ($q) use ($user) {
                    $q->where('assignee_id', $user->id)->orWhere('user_id', $user->id);
                })
                ->orderBy('priority', 'desc')
                ->limit(10)
                ->with('assignee')
                ->get();

            if ($matched->isNotEmpty()) {
                return $matched;
            }
        }

        return Issue::query()
            ->whereNotIn('status', ['done', 'closed', 'cancelled'])
            ->where('priority', '>=', Issue::PRIORITY_CRITICAL)
            ->where(function ($q) use ($user) {
                $q->where('assignee_id', $user->id)->orWhere('user_id', $user->id);
            })
            ->orderBy('priority', 'desc')
            ->limit(5)
            ->with('assignee')
            ->get();
    }

    /**
     * Filter raw issue_ids: keep only existing, open, owned by the user.
     * Trust-but-verify the agent's structured output.
     *
     * @return int[]
     */
    private function validateIssueIds(Profile $profile, array $rawIds): array
    {
        $ints = [];
        foreach ($rawIds as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ints[] = (int) $id;
            }
        }
        if (empty($ints)) {
            return [];
        }

        $userId = $profile->user_id;
        $valid = Issue::query()
            ->whereIn('id', $ints)
            ->whereNotIn('status', ['done', 'closed', 'cancelled'])
            ->where(function ($q) use ($userId) {
                $q->where('assignee_id', $userId)->orWhere('user_id', $userId);
            })
            ->pluck('id')
            ->all();

        // preserve original input order
        $validSet = array_flip($valid);
        return array_values(array_filter($ints, fn(int $id) => isset($validSet[$id])));
    }
}
