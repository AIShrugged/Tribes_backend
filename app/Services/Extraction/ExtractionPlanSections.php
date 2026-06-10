<?php

namespace App\Services\Extraction;

/**
 * Pure transforms between the IssueMergeService compute output and the stored plan JSON.
 *
 * The compute output is index-keyed (decisions[].index -> items[index]). The stored/edited plan is
 * uid-keyed so the moderation UI can delete/reorder rows without index drift. The approve handler
 * later rebuilds the index from the (possibly edited) item order by uid before calling applyPlan.
 */
class ExtractionPlanSections
{
    /**
     * Build the stored `issues` section from IssueMergeService::computePlan output.
     * Adds a stable `uid` to every item and stamps each decision with the uid it points at.
     *
     * @param  array{items: array<int, array>, decisions: ?array, existing_snapshots: array<int, array>}  $computed
     */
    public static function issues(array $computed): array
    {
        $items = [];
        foreach (array_values($computed['items'] ?? []) as $i => $item) {
            $items[] = array_merge(['uid' => 'i-'.$i], $item);
        }

        // decisions === null is the "create all" sentinel (no existing issues OR LLM failed) — preserve it.
        $decisions = null;
        if (is_array($computed['decisions'] ?? null)) {
            $decisions = [];
            foreach ($computed['decisions'] as $decision) {
                $idx = $decision['index'] ?? null;
                $uid = ($idx !== null && isset($items[$idx])) ? $items[$idx]['uid'] : null;
                $decisions[] = array_merge($decision, ['uid' => $uid]);
            }
        }

        return [
            'items'              => $items,
            'decisions'          => $decisions,
            'existing_snapshots' => $computed['existing_snapshots'] ?? [],
        ];
    }

    /**
     * Rebuild the index-keyed compute plan that IssueMergeService::applyPlan/applyPlanFromSource
     * consumes, from a (possibly user-edited) stored `issues` section. Items are re-indexed 0..N-1
     * in their current order; each decision's index is recomputed from its uid.
     *
     * @return array{items: array<int, array>, decisions: ?array}
     */
    public static function toComputePlan(array $section): array
    {
        $items = array_values($section['items'] ?? []);

        // Map uid -> new index after edits/reorder/deletes.
        $indexByUid = [];
        foreach ($items as $i => $item) {
            if (isset($item['uid'])) {
                $indexByUid[$item['uid']] = $i;
            }
        }

        $decisions = null;
        if (is_array($section['decisions'] ?? null)) {
            $decisions = [];
            foreach ($section['decisions'] as $decision) {
                $uid = $decision['uid'] ?? null;
                // Drop decisions whose item was removed; remap surviving ones to the new index.
                if ($uid === null || ! array_key_exists($uid, $indexByUid)) {
                    continue;
                }
                $decision['index'] = $indexByUid[$uid];
                $decisions[] = $decision;
            }
        }

        // Strip the uid from items — createIssue ignores it, but keep the payload clean.
        $cleanItems = array_map(function (array $item) {
            unset($item['uid']);

            return $item;
        }, $items);

        return ['items' => $cleanItems, 'decisions' => $decisions];
    }
}
