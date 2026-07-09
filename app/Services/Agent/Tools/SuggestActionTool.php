<?php

namespace App\Services\Agent\Tools;

use App\Models\BrainSuggestion;
use App\Services\Agent\Tools\Concerns\InteractsWithMcpTenant;

/**
 * The second brain's ONLY write tool: it proposes an action for human approval
 * instead of mutating the product directly. Each proposal is one row in
 * brain_suggestions; re-proposing the same thing (same dedupe_key) is idempotent
 * and never resurrects a proposal the user already rejected/applied.
 */
class SuggestActionTool extends AbstractAgentTool
{
    use InteractsWithMcpTenant;

    private const TTL_DAYS = 14;

    public function getName(): string
    {
        return 'suggest_action';
    }

    public function getDescription(): string
    {
        return 'Propose an action for a human to approve (the brain does NOT change data itself). '
            .'key=create_issue → payload {name, type, description?, team_id?, assignee_id?, due_date? (YYYY-MM-DD), source_type? (calendar_event), source_id?}. '
            .'key=update_task_status → payload {issue_id, status (open|in_progress|paused|review|reopen|done)}. '
            .'key=add_comment → payload {issue_id, comment}. Use this INSTEAD of create_issue when a task '
            .'for the same thing ALREADY EXISTS and you only have something to add (new info, a decision, a link). '
            // Post-meeting artifacts you generate yourself from a transcript. The payload JSON
            // must match these fields exactly (they map 1:1 to the product models).
            .'key=save_meeting_summary → payload {calendar_event_id, title, summary (markdown), key_points[] (strings), decisions[] (strings), commitments[] ({who,what,deadline})}. '
            .'key=save_meeting_agenda → payload {calendar_event_id (the NEXT meeting in the series), source_meeting_id (the processed meeting), type:"general", content (markdown), raw_json {meeting_goal, main_problem, discussion_topics[] ({title,description}), commitments_check[] ({person,commitment,deadline,status,question}), decisions_recap[] (strings)}}. '
            .'key=save_decision → payload {calendar_event_id, team_id, text, topic?, author_raw_name?} (one call per decision). '
            .'Always pass a deterministic dedupe_key so re-runs do not create duplicates '
            .'(e.g. "stalled:issue:533", "close:issue:951", "summary:meeting:228", "agenda:meeting:231", "decision:meeting:228:db-only-via-api", "task:meeting:228:add-premoderation"). '
            .'Include title (short), reasoning (why), and evidence (ids/quotes).';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'required' => ['key', 'title', 'dedupe_key', 'payload'],
            'properties' => [
                'key' => [
                    'type' => 'string',
                    'enum' => BrainSuggestion::KEYS,
                    'description' => 'The proposed action type.',
                ],
                'title' => ['type' => 'string', 'description' => 'Short human-readable title of the proposal.'],
                'summary' => ['type' => 'string', 'description' => 'Optional one-paragraph summary.'],
                'reasoning' => ['type' => 'string', 'description' => 'Why you propose this (the brain\'s justification).'],
                'confidence' => ['type' => 'integer', 'description' => '0–100 confidence.'],
                'dedupe_key' => ['type' => 'string', 'description' => 'Deterministic key; same key = same proposal (idempotent).'],
                'payload' => [
                    'type' => 'object',
                    'description' => 'Action intent fields (see description for the shape per key).',
                ],
                'evidence' => [
                    'type' => 'object',
                    'description' => 'Optional structured evidence: {meeting_id, decision_id, issue_id, quote}.',
                ],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $key = (string) ($parameters['key'] ?? '');
        $title = trim((string) ($parameters['title'] ?? ''));
        $dedupeKey = trim((string) ($parameters['dedupe_key'] ?? ''));
        $payload = $parameters['payload'] ?? null;

        if (! in_array($key, BrainSuggestion::KEYS, true)) {
            return ['success' => false, 'error' => 'Unknown key. Allowed: '.implode(', ', BrainSuggestion::KEYS)];
        }
        if ($title === '') {
            return ['success' => false, 'error' => 'title is required'];
        }
        if ($dedupeKey === '') {
            return ['success' => false, 'error' => 'dedupe_key is required'];
        }
        if (! is_array($payload)) {
            return ['success' => false, 'error' => 'payload must be an object'];
        }

        $shapeError = $this->validateIntentShape($key, $payload);
        if ($shapeError !== null) {
            return ['success' => false, 'error' => $shapeError];
        }

        $user = $this->currentUser();
        $organizationId = $this->currentOrganizationId($user);
        if (! $user || ! $organizationId) {
            return ['success' => false, 'error' => 'Not authenticated or organization is ambiguous.'];
        }

        $existing = BrainSuggestion::query()
            ->where('organization_id', $organizationId)
            ->where('dedupe_key', $dedupeKey)
            ->first();

        // Never resurrect a proposal the user already decided on.
        if ($existing && ! $existing->isPending()) {
            return [
                'success' => true,
                'suggestion_id' => $existing->id,
                'status' => $existing->status,
                'message' => "Already {$existing->status}; not re-created.",
            ];
        }

        $attributes = [
            'organization_id' => $organizationId,
            'run_uuid' => $this->runUuid(),
            'key' => $key,
            'payload_version' => 1,
            'payload' => $payload,
            'title' => $title,
            'summary' => isset($parameters['summary']) ? (string) $parameters['summary'] : null,
            'reasoning' => isset($parameters['reasoning']) ? (string) $parameters['reasoning'] : null,
            'evidence' => is_array($parameters['evidence'] ?? null) ? $parameters['evidence'] : null,
            'confidence' => isset($parameters['confidence']) ? (int) $parameters['confidence'] : null,
            'dedupe_key' => $dedupeKey,
            'status' => BrainSuggestion::STATUS_PENDING,
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ];

        if ($existing) {
            $existing->update($attributes);
            $suggestion = $existing;
        } else {
            $suggestion = BrainSuggestion::create($attributes);
        }

        return [
            'success' => true,
            'suggestion_id' => $suggestion->id,
            'status' => $suggestion->status,
            'created' => ! $existing,
        ];
    }

    private function validateIntentShape(string $key, array $payload): ?string
    {
        return match ($key) {
            BrainSuggestion::KEY_CREATE_ISSUE => (trim((string) ($payload['name'] ?? '')) === '' || trim((string) ($payload['type'] ?? '')) === '')
                ? 'create_issue payload requires name and type'
                : null,
            BrainSuggestion::KEY_UPDATE_TASK_STATUS => (empty($payload['issue_id']) || trim((string) ($payload['status'] ?? '')) === '')
                ? 'update_task_status payload requires issue_id and status'
                : null,
            BrainSuggestion::KEY_ADD_COMMENT => (empty($payload['issue_id']) || trim((string) ($payload['comment'] ?? '')) === '')
                ? 'add_comment payload requires issue_id and comment'
                : null,
            BrainSuggestion::KEY_SAVE_MEETING_SUMMARY => ((int) ($payload['calendar_event_id'] ?? 0) <= 0 || trim((string) ($payload['summary'] ?? '')) === '')
                ? 'save_meeting_summary payload requires calendar_event_id and summary'
                : null,
            BrainSuggestion::KEY_SAVE_MEETING_AGENDA => ((int) ($payload['calendar_event_id'] ?? 0) <= 0 || trim((string) ($payload['content'] ?? '')) === '')
                ? 'save_meeting_agenda payload requires calendar_event_id and content'
                : null,
            BrainSuggestion::KEY_SAVE_DECISION => ((int) ($payload['calendar_event_id'] ?? 0) <= 0 || (int) ($payload['team_id'] ?? 0) <= 0 || trim((string) ($payload['text'] ?? '')) === '')
                ? 'save_decision payload requires calendar_event_id, team_id and text'
                : null,
            default => 'Unsupported key',
        };
    }

    /** Best-effort run correlation from the X-Brain-Run header if the sidecar sets it. */
    private function runUuid(): ?string
    {
        $run = request()?->header('X-Brain-Run');

        return is_string($run) && $run !== '' ? substr($run, 0, 64) : null;
    }
}
