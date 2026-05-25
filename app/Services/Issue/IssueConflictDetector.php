<?php

namespace App\Services\Issue;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\MeetingTaskStatus;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\IssueConflict;
use App\Models\MeetingSummary;
use App\Models\Setting;
use App\Services\LlmPromptService;
use App\Services\OpenRouterClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IssueConflictDetector
{
    public function __construct(
        private readonly OpenRouterClient $llm,
    ) {}

    /**
     * Detect conflicts among $newIssues vs existing open issues (team scope for tasks,
     * org scope for epics) and persist them. Idempotent per-event: re-running for the
     * same $event wipes prior open conflicts before recording new ones.
     *
     * Returns the persisted group payloads (for downstream notifiers).
     *
     * @param  int[]  $newIssueIds
     * @return array<int, array{group_uuid:string, members:array, fields:string[], summary:string}>
     */
    public function detect(array $newIssueIds, ?CalendarEvent $event): array
    {
        if (empty($newIssueIds)) {
            return [];
        }

        $newIssues = Issue::query()
            ->whereIn('id', $newIssueIds)
            ->with('assignee:id,name')
            ->get(['id', 'name', 'description', 'due_date', 'assignee_id', 'team_id', 'organization_id', 'type']);

        if ($newIssues->isEmpty()) {
            return [];
        }

        $pool = $this->loadExistingPool($newIssues);

        if ($pool->isEmpty()) {
            $this->persist([], $event); // still clear stale open conflicts for this event
            return [];
        }

        $groups = $this->askLlm($newIssues, $pool);

        return $this->persist($groups, $event);
    }

    /**
     * Build comparison pool: open team issues for tasks, open org epics for epics.
     */
    private function loadExistingPool(Collection $newIssues): Collection
    {
        $newIds = $newIssues->pluck('id')->all();
        $teamIds = $newIssues->where('type', '!=', Issue::TYPE_EPIC)->pluck('team_id')->filter()->unique()->all();
        $orgIds = $newIssues->where('type', Issue::TYPE_EPIC)->pluck('organization_id')->filter()->unique()->all();

        $pool = collect();

        if (! empty($teamIds)) {
            $pool = $pool->merge(
                Issue::query()
                    ->whereIn('team_id', $teamIds)
                    ->where('type', '!=', Issue::TYPE_EPIC)
                    ->whereNotIn('id', $newIds)
                    ->where('status', '!=', MeetingTaskStatus::DONE->value)
                    ->with('assignee:id,name')
                    ->get(['id', 'name', 'description', 'due_date', 'assignee_id', 'team_id', 'organization_id', 'type'])
            );
        }

        if (! empty($orgIds)) {
            $pool = $pool->merge(
                Issue::query()
                    ->whereIn('organization_id', $orgIds)
                    ->where('type', Issue::TYPE_EPIC)
                    ->whereNotIn('id', $newIds)
                    ->where('status', '!=', MeetingTaskStatus::DONE->value)
                    ->with('assignee:id,name')
                    ->get(['id', 'name', 'description', 'due_date', 'assignee_id', 'team_id', 'organization_id', 'type'])
            );
        }

        return $pool->unique('id')->values();
    }

    /**
     * Call LLM. Returns groups as ['members' => [{issue_id,role}], 'fields' => [..], 'summary' => '..'].
     *
     * Errors propagate to the caller so that DetectIssueConflictsJob's retry kicks in
     * (CLAUDE.md rule 3 — LLM listeners must re-throw, not swallow).
     *
     * @return array<int, array>
     */
    private function askLlm(Collection $newIssues, Collection $pool): array
    {
        $payload = [
            'new_issues' => $newIssues->map(fn (Issue $i) => $this->issuePayload($i, 'new'))->values()->all(),
            'existing_issues' => $pool->map(fn (Issue $i) => $this->issuePayload($i, 'existing'))->values()->all(),
        ];

        $json = $this->llm->chat(
            messages: [
                new MessageDTO('system', $this->systemPrompt()),
                new MessageDTO('user', json_encode($payload, JSON_UNESCAPED_UNICODE)),
            ],
            model: Setting::get('model.followup', config('ai.providers.openrouter.models.followup')),
            maxTokens: 4096,
            forceJsonResponse: true,
        );

        if (is_string($json) && preg_match('/\{[\s\S]*\}/s', $json, $matches)) {
            $json = $matches[0];
        }
        $decoded = is_string($json) ? json_decode($json, true) : $json;

        return $decoded['groups'] ?? [];
    }

    /**
     * Persist groups + MeetingSummary.conflicts snapshot atomically.
     *
     * @param  array<int, array>  $groups
     * @return array<int, array{group_uuid:string, members:array, fields:string[], summary:string}>
     */
    public function persist(array $groups, ?CalendarEvent $event): array
    {
        return DB::transaction(function () use ($groups, $event) {
            if ($event !== null) {
                // Lock the row to serialise concurrent pipelines for the same event.
                DB::table('calendar_events')->where('id', $event->id)->lockForUpdate()->first();

                // Idempotency: wipe prior OPEN conflicts for this event. Preserve
                // user-resolved/ignored rows.
                IssueConflict::query()
                    ->where('detected_in_calendar_event_id', $event->id)
                    ->where('status', IssueConflict::STATUS_OPEN)
                    ->delete();
            }

            $persisted = [];

            foreach ($groups as $group) {
                $members = $group['members'] ?? [];
                $fields  = $group['fields'] ?? [];
                $summary = trim((string) ($group['summary'] ?? ''));

                $memberIds = array_values(array_filter(array_unique(array_map(
                    static fn (array $m) => (int) ($m['issue_id'] ?? 0),
                    $members,
                ))));
                $validFields = array_values(array_filter(
                    array_map('strval', $fields),
                    static fn (string $f) => in_array($f, IssueConflict::FIELDS, true),
                ));

                if (count($memberIds) < 2 || empty($validFields) || $summary === '') {
                    continue;
                }

                $groupUuid = (string) Str::orderedUuid();

                foreach ($memberIds as $issueId) {
                    foreach ($validFields as $field) {
                        IssueConflict::create([
                            'conflict_group_uuid'           => $groupUuid,
                            'issue_id'                      => $issueId,
                            'field'                         => $field,
                            'conflict_summary'              => $summary,
                            'detected_in_calendar_event_id' => $event?->id,
                            'status'                        => IssueConflict::STATUS_OPEN,
                        ]);
                    }
                }

                $persisted[] = [
                    'group_uuid' => $groupUuid,
                    'members'    => array_map(static fn (int $id) => ['issue_id' => $id], $memberIds),
                    'fields'     => $validFields,
                    'summary'    => $summary,
                ];
            }

            if ($event !== null) {
                $summaryRow = MeetingSummary::query()->where('calendar_event_id', $event->id)->first();
                if ($summaryRow !== null) {
                    $summaryRow->update(['conflicts' => $persisted]);
                } else {
                    Log::warning('IssueConflictDetector: MeetingSummary missing, snapshot dropped', [
                        'calendar_event_id' => $event->id,
                        'groups_count'      => count($persisted),
                    ]);
                }
            }

            if (! empty($persisted)) {
                Log::info('IssueConflictDetector: persisted', [
                    'calendar_event_id' => $event?->id,
                    'groups'            => count($persisted),
                ]);
            }

            return $persisted;
        });
    }

    private function issuePayload(Issue $issue, string $role): array
    {
        // Eager-loading is set up in detect() so this doesn't trigger N+1.
        $assigneeName = $issue->assignee?->name;

        return [
            'id'              => $issue->id,
            'role'            => $role,
            'type'            => $issue->type,
            'name'            => $issue->name,
            'description'     => mb_substr(strip_tags((string) $issue->description), 0, 300),
            'due_date'        => $issue->due_date?->toDateString(),
            'assignee'        => $assigneeName,
            'team_id'         => $issue->team_id,
        ];
    }

    private function systemPrompt(): string
    {
        return app(LlmPromptService::class)->renderView(
            slug: 'issue.conflict_detector.system',
            organizationId: null,
            fallbackView: 'llm-prompts.issue.conflict-detector-system',
            name: 'Issue conflict detector system prompt',
        );
    }
}
