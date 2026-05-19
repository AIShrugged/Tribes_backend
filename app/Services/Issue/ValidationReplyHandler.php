<?php

namespace App\Services\Issue;

use App\Exceptions\AppException;
use App\Models\IssueAgentFlowPendingReply;
use App\Services\IssueAgentFlowService;
use Illuminate\Support\Facades\Log;

class ValidationReplyHandler
{
    public function __construct(
        private readonly IssueAgentFlowService $flowService,
    ) {}

    /**
     * Try to apply a Telegram reply as the answer to an outstanding IssueAgentFlow
     * validation question. Returns the outcome (or null if no pending match).
     *
     * Caller is responsible for delivering the confirmation/warning text back
     * to the user via the appropriate channel.
     */
    public function handleTelegramReply(
        int $chatId,
        int $replyToMessageId,
        int $userId,
        string $answers,
    ): ?ValidationReplyOutcome {
        $pending = IssueAgentFlowPendingReply::query()
            ->active()
            ->where('telegram_chat_id', $chatId)
            ->where('telegram_message_id', $replyToMessageId)
            ->where('user_id', $userId)
            ->first();

        if (! $pending) {
            return null;
        }

        $issue = $pending->issue()->first();
        if (! $issue) {
            $pending->markConsumed();

            return null;
        }

        try {
            $this->flowService->answer($issue, $answers);
            $pending->markConsumed();

            return new ValidationReplyOutcome(
                issueId: $issue->id,
                accepted: true,
                message: "✅ Принято, запускаю планирование задачи #{$issue->id}.",
            );
        } catch (AppException $e) {
            $pending->markConsumed();
            Log::info('ValidationReplyHandler: reply could not be applied', [
                'issue_id' => $issue->id,
                'flow_id' => $pending->issue_agent_flow_id,
                'code' => $e->getCode(),
            ]);

            return new ValidationReplyOutcome(
                issueId: $issue->id,
                accepted: false,
                message: "⚠️ Валидация задачи #{$issue->id} уже завершена: {$e->getMessage()}",
            );
        }
    }
}
