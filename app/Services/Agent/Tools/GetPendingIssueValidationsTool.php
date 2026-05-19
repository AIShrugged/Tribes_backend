<?php

namespace App\Services\Agent\Tools;

use App\Models\IssueAgentFlowPendingReply;
use App\Models\User;

/**
 * Lists IssueAgentFlow validation questions waiting for the current user's answer.
 *
 * When the user previously launched a development flow on an issue, a task-validator
 * agent might decide more context is needed and post follow-up questions. The flow
 * then sits in WAITING_FOR_USER until the user answers (via reply in Telegram, or
 * via answer_issue_validation in this agent).
 *
 * This tool surfaces those pending questions so the LLM can detect that an
 * incoming user message is an answer to them and call answer_issue_validation.
 */
class GetPendingIssueValidationsTool extends AbstractAgentTool
{
    public function __construct(
        private readonly User $user,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'get_pending_issue_validations';
    }

    public function getDescription(): string
    {
        return 'List issues whose development flow is paused waiting for the current user to answer SMART/DoD validator questions. Returns issue_id, issue_name, questions[] and asked_at for each pending validation. Use this when an incoming user message looks like a free-text answer to a clarifying question — to find which issue it belongs to. If exactly one match, call answer_issue_validation; if several, ask the user which issue they mean.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $pending = IssueAgentFlowPendingReply::query()
            ->active()
            ->where('user_id', $this->user->id)
            ->with('issue:id,name,status')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        if ($pending->isEmpty()) {
            return [
                'success' => true,
                'pending_count' => 0,
                'pending' => [],
            ];
        }

        return [
            'success' => true,
            'pending_count' => $pending->count(),
            'pending' => $pending->map(fn (IssueAgentFlowPendingReply $p) => [
                'issue_id' => $p->issue_id,
                'issue_name' => $p->issue?->name,
                'flow_id' => $p->issue_agent_flow_id,
                'questions' => $p->questions ?? [],
                'asked_at' => $p->created_at?->toIso8601String(),
                'expires_at' => $p->expires_at?->toIso8601String(),
            ])->values()->toArray(),
        ];
    }
}
