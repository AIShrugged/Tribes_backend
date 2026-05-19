<?php

namespace App\Services\Agent\Tools;

use App\Exceptions\AppException;
use App\Models\Issue;
use App\Models\IssueAgentFlowPendingReply;
use App\Models\User;
use App\Services\IssueAgentFlowService;

/**
 * Submits the user's free-text answers to an IssueAgentFlow validator's
 * questions. Appends the answer to the issue description, transitions the
 * flow out of WAITING_FOR_USER, and dispatches the next (planning) step.
 *
 * Use this when:
 *  - get_pending_issue_validations shows the user has an active pending
 *    validation, AND
 *  - the incoming user message reads as a substantive answer to those
 *    questions (not a tangential or off-topic remark).
 *
 * When multiple pendings exist, ask the user to disambiguate first.
 */
class AnswerIssueValidationTool extends AbstractAgentTool
{
    public function __construct(
        private readonly User $user,
        private readonly IssueAgentFlowService $flowService,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'answer_issue_validation';
    }

    public function getDescription(): string
    {
        return 'Submit the user\'s answer(s) to a pending issue validation. Appends the text to the issue description under "## Ответы на уточняющие вопросы" and resumes the development flow (planning step is dispatched). Call only after confirming via get_pending_issue_validations that the target issue is in WAITING_FOR_USER state and belongs to the current user.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'issue_id' => [
                    'type' => 'integer',
                    'description' => 'ID of the issue with the pending validation (see get_pending_issue_validations).',
                ],
                'answers' => [
                    'type' => 'string',
                    'description' => 'The user\'s free-text answer(s) to the validator\'s questions. Will be appended to the issue description as-is, so include enough context to make the answer self-contained.',
                ],
            ],
            'required' => ['issue_id', 'answers'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $issueId = $parameters['issue_id'] ?? null;
        $answers = trim((string) ($parameters['answers'] ?? ''));

        if (! $issueId || $answers === '') {
            return ['success' => false, 'error' => 'issue_id and non-empty answers are required'];
        }

        $issue = Issue::query()->find($issueId);
        if (! $issue) {
            return ['success' => false, 'error' => "Issue #{$issueId} not found"];
        }

        // Authorization: only the user owning a pending validation for this issue can answer.
        $pending = IssueAgentFlowPendingReply::query()
            ->active()
            ->where('user_id', $this->user->id)
            ->where('issue_id', $issue->id)
            ->orderByDesc('created_at')
            ->first();

        if (! $pending) {
            return [
                'success' => false,
                'error' => "No active pending validation for issue #{$issue->id} for the current user. Call get_pending_issue_validations to verify state.",
            ];
        }

        try {
            $this->flowService->answer($issue, $answers);
            $pending->markConsumed();
        } catch (AppException $e) {
            $pending->markConsumed();

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ];
        }

        return [
            'success' => true,
            'issue_id' => $issue->id,
            'issue_name' => $issue->name,
            'message' => "Validation answer accepted; planning step dispatched.",
        ];
    }
}
