<?php

namespace App\Services\Agent\Tools;

use App\Enums\ConversationChannelType;
use App\Models\User;
use App\Services\Channel\ChannelRuntimeService;
use App\Services\Channel\UserChannelTargetResolver;
use App\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Builder;

class SendUserMessageTool extends AbstractAgentTool
{
    public function __construct(
        private readonly User $user,
        private readonly UserChannelTargetResolver $targetResolver,
        private readonly ChannelRuntimeService $channelRuntimeService,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'send_user_message';
    }

    public function getDescription(): string
    {
        return 'Send a proactive assistant message/reminder to the current user or to another accessible teammate. '
            .'For teammate reminders the tool automatically sends to Telegram when the recipient has linked Telegram, otherwise falls back to web chat.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'required' => ['content'],
            'properties' => [
                'channel' => [
                    'type' => 'string',
                    'enum' => [ConversationChannelType::WEB_CHAT->value, ConversationChannelType::TELEGRAM->value],
                    'description' => 'Optional outbound channel override. For teammate reminders omit this so Telegram is preferred automatically.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Message content to deliver to the user.',
                ],
                'chat_id' => [
                    'type' => 'integer',
                    'description' => 'Optional web chat id. If omitted, the latest chat owned by the user is used.',
                ],
                'telegram_chat_id' => [
                    'type' => 'integer',
                    'description' => 'Optional Telegram chat id. If omitted, the latest Telegram conversation linked to the user is used.',
                ],
                'message_thread_id' => [
                    'type' => 'integer',
                    'description' => 'Optional Telegram thread id to further narrow the target conversation.',
                ],
                'target_user_id' => [
                    'type' => 'integer',
                    'description' => 'Optional recipient user id. If omitted, send to the current user.',
                ],
                'target_name' => [
                    'type' => 'string',
                    'description' => 'Optional recipient name for teammate reminders. Use only when target_user_id is unknown.',
                ],
                'target_email' => [
                    'type' => 'string',
                    'description' => 'Optional recipient email for teammate reminders. Use only when target_user_id is unknown.',
                ],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];
        $content = trim((string) ($parameters['content'] ?? ''));

        if ($content === '') {
            return ['success' => false, 'error' => 'Message content must not be empty.'];
        }

        // Treat 0 and empty values as null — LLMs often fill optional IDs with 0/1 instead of omitting
        $chatId = ! empty($parameters['chat_id']) ? (int) $parameters['chat_id'] : null;
        $telegramChatId = ! empty($parameters['telegram_chat_id']) ? (int) $parameters['telegram_chat_id'] : null;
        $messageThreadId = ! empty($parameters['message_thread_id']) ? (int) $parameters['message_thread_id'] : null;
        $recipient = $this->resolveRecipient($parameters);

        if (is_array($recipient)) {
            return $recipient;
        }

        $channel = $this->resolveChannel($recipient);

        $conversation = $this->targetResolver->resolve(
            $recipient,
            $channel,
            $chatId,
            $telegramChatId,
            $messageThreadId,
        );

        if ($conversation === null && $channel === ConversationChannelType::TELEGRAM && $recipient->telegramUser !== null) {
            $conversation = $this->targetResolver->resolve(
                $recipient,
                $channel,
                null,
                (int) $recipient->telegramUser->telegram_user_id,
                $messageThreadId,
            );
        }

        if ($conversation === null) {
            return ['success' => false, 'error' => 'No eligible conversation found for the requested channel.'];
        }

        $message = $this->channelRuntimeService->deliverToConversation(
            $conversation,
            $content,
            null,
            [
                'metadata' => [
                    'source' => 'agent_tool',
                    'tool_name' => $this->getName(),
                ],
            ],
        );

        return [
            'success' => true,
            'recipient' => [
                'id' => $recipient->id,
                'name' => $recipient->name,
                'email' => $recipient->email,
            ],
            'conversation' => [
                'id' => $conversation->id,
                'channel_type' => $conversation->channel_type->value,
                'chat_id' => $conversation->chat_id,
                'telegram_chat_id' => $conversation->telegram_chat_id,
                'message_thread_id' => $conversation->message_thread_id,
            ],
            'message' => $message ? [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
            ] : null,
        ];
    }

    private function resolveChannel(User $recipient): ConversationChannelType
    {
        if ($recipient->telegramUser !== null) {
            return ConversationChannelType::TELEGRAM;
        }

        return ConversationChannelType::WEB_CHAT;
    }

    private function resolveRecipient(array $parameters): User|array
    {
        $targetUserId = ! empty($parameters['target_user_id']) ? (int) $parameters['target_user_id'] : null;
        $targetEmail = trim((string) ($parameters['target_email'] ?? ''));
        $targetName = trim((string) ($parameters['target_name'] ?? ''));

        if ($targetUserId === null && $targetEmail === '' && $targetName === '') {
            return $this->user;
        }

        $query = User::query();

        if ($targetUserId !== null) {
            $query->whereKey($targetUserId);
        } elseif ($targetEmail !== '') {
            $query->whereRaw('LOWER(email) = ?', [mb_strtolower($targetEmail)]);
        }

        $query
            ->where(function (Builder $builder): void {
                $builder->whereKey($this->user->id)
                    ->orWhereHas('organizations', function (Builder $relation): void {
                        $relation->whereIn(
                            'organizations.id',
                            $this->user->organizations()->select('organizations.id')
                        );
                    })
                    ->orWhereHas('teams', function (Builder $relation): void {
                        $relation->whereIn(
                            'teams.id',
                            $this->user->teams()->select('teams.id')
                        );
                    });
            });

        $candidates = $targetName === ''
            ? $query->limit(2)->get()
            : $query->get()->filter(function (User $user) use ($targetName): bool {
                $candidate = NameNormalizer::normalize((string) $user->name);
                $target = NameNormalizer::normalize($targetName);

                return str_contains($candidate, $target)
                    || str_contains($target, $candidate)
                    || str_starts_with($candidate, $target)
                    || str_starts_with($target, $candidate);
            })->values()->take(2);

        if ($candidates->isEmpty()) {
            return ['success' => false, 'error' => 'Recipient not found or not accessible.'];
        }

        if ($candidates->count() > 1) {
            return [
                'success' => false,
                'error' => 'Multiple recipients matched. Provide target_user_id.',
                'matches' => $candidates->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])->all(),
            ];
        }

        return $candidates->first();
    }
}
