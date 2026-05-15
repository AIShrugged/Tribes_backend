<?php

namespace App\Services\Issue;

use App\Enums\MeetingTaskStatus;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Team;
use App\Models\User;
use App\Services\Decisions\DecisionAuthorResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EpicMergeService
{
    public function __construct(
        private readonly DecisionAuthorResolver $authorResolver,
    ) {}

    /**
     * Persist LLM decisions about epics: create new, update existing, skip duplicates.
     * Also relinks child meeting issues to the resulting epic via Issue.epic_id.
     *
     * @param  array<int, array>  $items  LLM-decoded `epics[]` array
     * @return array{created: Collection<int, Issue>, updated: Collection<int, Issue>}
     */
    public function persist(
        array $items,
        CalendarEvent $event,
        Team $team,
        User $owner,
        Collection $existingEpics,
        Collection $meetingIssueIds,
        Collection $meetingDecisionIds = new Collection(),
    ): array {
        $created = collect();
        $updated = collect();

        if (empty($items)) {
            return ['created' => $created, 'updated' => $updated];
        }

        DB::transaction(function () use ($items, $event, $team, $owner, $existingEpics, $meetingIssueIds, $meetingDecisionIds, &$created, &$updated): void {
            $existingById = $existingEpics->keyBy('id');

            foreach ($items as $item) {
                $action = $item['action'] ?? 'skip';

                if ($action === 'skip') {
                    continue;
                }

                $epic = null;

                if ($action === 'update') {
                    $epicId = $item['existing_epic_id'] ?? null;
                    $existing = $epicId !== null ? $existingById->get((int) $epicId) : null;

                    if ($existing) {
                        $this->updateEpic($existing, $item, $event, $owner);
                        $updated->push($existing->fresh());
                        $epic = $existing;
                    } else {
                        // Defensive: LLM returned a non-existent epic id → degrade to create.
                        $epic = $this->createEpic($item, $event, $team, $owner, $meetingDecisionIds);
                        $created->push($epic);
                    }
                } else { // create
                    $epic = $this->createEpic($item, $event, $team, $owner, $meetingDecisionIds);
                    $created->push($epic);
                }

                $this->linkChildIssues($epic, $item['child_issue_ids'] ?? [], $meetingIssueIds);
            }
        });

        if ($created->isNotEmpty() || $updated->isNotEmpty()) {
            Log::info('EpicMergeService: persisted', [
                'calendar_event_id' => $event->id,
                'created'           => $created->count(),
                'updated'           => $updated->count(),
            ]);
        }

        return ['created' => $created, 'updated' => $updated];
    }

    private function createEpic(array $item, CalendarEvent $event, Team $team, User $owner, Collection $meetingDecisionIds): Issue
    {
        $scope    = $item['scope'] ?? 'team';
        $teamId   = $scope === 'organization' ? null : $team->id;
        $authorId = $this->resolveAuthorUserId($item['author_name'] ?? null, $event, $owner);

        // US-6.10: tie the epic to the decision that motivated it. Trust the LLM only if the id
        // came from this meeting's decisions[] — defends against hallucinated ids.
        $sourceDecisionId = isset($item['source_decision_id']) && $meetingDecisionIds->contains((int) $item['source_decision_id'])
            ? (int) $item['source_decision_id']
            : null;

        return Issue::create([
            'user_id'                 => $authorId,
            'organization_id'         => $team->organization_id,
            'team_id'                 => $teamId,
            'sourceable_type'         => CalendarEvent::class,
            'sourceable_id'           => $event->id,
            'name'                    => trim((string) ($item['name'] ?? '')),
            'description'             => $item['description'] ?? null,
            'type'                    => Issue::TYPE_EPIC,
            'status'                  => MeetingTaskStatus::OPEN->value,
            'source_protocol_item_id' => $sourceDecisionId,
        ]);
    }

    private function updateEpic(Issue $existing, array $item, CalendarEvent $event, User $fallback): void
    {
        // Per US-7.1: existing epic name/description are immutable during merge.
        // All meeting-driven updates are recorded as IssueComment entries instead.
        $updateText = trim((string) ($item['update_description'] ?? ''));
        if ($updateText === '') {
            return;
        }

        $dateStr  = $event->starts_at->toDateString();
        $authorId = $this->resolveCommentAuthorUserId($item['author_name'] ?? null, $event, $fallback);

        IssueComment::create([
            'issue_id'          => $existing->id,
            'user_id'           => $authorId,
            'parent_id'         => null,
            'calendar_event_id' => $event->id,
            'content'           => "**Обновление цели по встрече \"{$event->title}\" от {$dateStr}:**\n\n{$updateText}",
        ]);
    }

    private function linkChildIssues(Issue $epic, array $childIds, Collection $meetingIssueIds): void
    {
        if (empty($childIds)) {
            return;
        }

        $validIds = collect($childIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $meetingIssueIds->contains($id) && $id !== $epic->id)
            ->unique()
            ->values();

        if ($validIds->isEmpty()) {
            return;
        }

        // Per-row save (not mass update) so IssueObserver fires and CPM invalidates.
        foreach (Issue::whereIn('id', $validIds)->get() as $child) {
            $child->update(['epic_id' => $epic->id]);
        }
    }

    private function resolveAuthorUserId(?string $authorName, CalendarEvent $event, User $fallback): int
    {
        if (blank($authorName)) {
            return $fallback->id;
        }

        return $this->authorResolver->resolve($event, $authorName)['user_id'] ?? $fallback->id;
    }

    /**
     * Always returns a user id — falls back to the caller user when LLM didn't emit
     * an author or the resolver couldn't match. Mirrors IssueMergeService (US-6.8).
     */
    private function resolveCommentAuthorUserId(?string $authorName, CalendarEvent $event, User $fallback): int
    {
        if (!blank($authorName)) {
            $resolved = $this->authorResolver->resolve($event, $authorName)['user_id'] ?? null;
            if ($resolved) {
                return $resolved;
            }
        }

        return $fallback->id;
    }
}
