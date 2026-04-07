<?php

namespace App\Services\Issue;

use App\Enums\ConversationChannelType;
use App\Models\Issue;
use App\Models\User;
use App\Services\Channel\ChannelRuntimeService;
use App\Services\Channel\UserChannelTargetResolver;
use Illuminate\Support\Facades\Log;

/**
 * Sends post-delete notifications after an Issue (task) is permanently or soft-deleted.
 *
 * Two channels are attempted independently:
 *   1. Telegram – a message is delivered to the assignee's linked Telegram conversation.
 *   2. Admin UI  – a message is delivered to the assignee's web-chat conversation.
 *
 * If either channel is unavailable or throws, the error is logged and the other
 * channel is still attempted.  Neither failure rolls back the deletion.
 */
class IssueDeletedNotificationService
{
    public function __construct(
        private readonly UserChannelTargetResolver $targetResolver,
        private readonly ChannelRuntimeService $channelRuntimeService,
    ) {}

    /**
     * Fire all post-delete notifications.
     *
     * @param  Issue  $issue        The issue snapshot captured BEFORE deletion.
     * @param  User   $actor        The user (human or agent runner) who triggered the deletion.
     */
    public function notify(Issue $issue, User $actor): void
    {
        $assignee = $issue->assignee;

        if ($assignee === null) {
            Log::info('IssueDeletedNotificationService: no assignee, skipping notifications', [
                'issue_id' => $issue->id,
                'actor_id' => $actor->id,
            ]);

            return;
        }

        $message = $this->buildMessage($issue, $actor);

        $this->sendTelegram($assignee, $message, $issue, $actor);
        $this->sendAdminUi($assignee, $message, $issue, $actor);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function buildMessage(Issue $issue, User $actor): string
    {
        return sprintf(
            "🗑 Task #%d \"%s\" has been deleted by %s.",
            $issue->id,
            $issue->name,
            $actor->name ?? "user #{$actor->id}",
        );
    }

    private function sendTelegram(User $assignee, string $message, Issue $issue, User $actor): void
    {
        try {
            $conversation = $this->targetResolver->resolve(
                $assignee,
                ConversationChannelType::TELEGRAM,
            );

            if ($conversation === null) {
                Log::info('IssueDeletedNotificationService: no Telegram conversation for assignee', [
                    'issue_id'    => $issue->id,
                    'assignee_id' => $assignee->id,
                ]);

                return;
            }

            $this->channelRuntimeService->deliverToConversation(
                $conversation,
                $message,
                null,
                [
                    'metadata' => [
                        'source'    => 'issue_deleted_notification',
                        'issue_id'  => $issue->id,
                        'actor_id'  => $actor->id,
                    ],
                ],
            );

            Log::info('IssueDeletedNotificationService: Telegram notification sent', [
                'issue_id'         => $issue->id,
                'assignee_id'      => $assignee->id,
                'conversation_id'  => $conversation->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('IssueDeletedNotificationService: Telegram notification failed', [
                'issue_id'    => $issue->id,
                'assignee_id' => $assignee->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    private function sendAdminUi(User $assignee, string $message, Issue $issue, User $actor): void
    {
        try {
            $conversation = $this->targetResolver->resolve(
                $assignee,
                ConversationChannelType::WEB_CHAT,
            );

            if ($conversation === null) {
                Log::info('IssueDeletedNotificationService: no admin UI (web-chat) conversation for assignee', [
                    'issue_id'    => $issue->id,
                    'assignee_id' => $assignee->id,
                ]);

                return;
            }

            $this->channelRuntimeService->deliverToConversation(
                $conversation,
                $message,
                null,
                [
                    'metadata' => [
                        'source'    => 'issue_deleted_notification',
                        'issue_id'  => $issue->id,
                        'actor_id'  => $actor->id,
                    ],
                ],
            );

            Log::info('IssueDeletedNotificationService: admin UI notification sent', [
                'issue_id'         => $issue->id,
                'assignee_id'      => $assignee->id,
                'conversation_id'  => $conversation->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('IssueDeletedNotificationService: admin UI notification failed', [
                'issue_id'    => $issue->id,
                'assignee_id' => $assignee->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}