<?php

namespace App\Services\Agent\Tools;

use App\Enums\ConversationChannelType;
use App\Models\User;
use App\Services\Channel\ChannelRuntimeService;
use App\Services\Channel\UserChannelTargetResolver;

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
        return 'Send a proactive assistant message to the current user in web chat or Telegram using an existing linked conversation.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'required' => ['channel', 'content'],
            'properties' => [
                'channel' => [
                    'type' => 'string',
                    'enum' => [ConversationChannelType::WEB_CHAT->value, ConversationChannelType::TELEGRAM->value],
                    'description' => 'Outbound channel to use.',
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
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];
        $content = trim((string) ($parameters['content'] ?? ''));
        $channel = ConversationChannelType::tryFrom((string) ($parameters['channel'] ?? ''));

        if ($channel === null) {
            return ['success' => false, 'error' => 'Unsupported channel.'];
        }

        if ($content === '') {
            return ['success' => false, 'error' => 'Message content must not be empty.'];
        }

        // Treat 0 and empty values as null — LLMs often fill optional IDs with 0/1 instead of omitting
        $chatId = ! empty($parameters['chat_id']) ? (int) $parameters['chat_id'] : null;
        $telegramChatId = ! empty($parameters['telegram_chat_id']) ? (int) $parameters['telegram_chat_id'] : null;
        $messageThreadId = ! empty($parameters['message_thread_id']) ? (int) $parameters['message_thread_id'] : null;

        $conversation = $this->targetResolver->resolve(
            $this->user,
            $channel,
            $chatId,
            $telegramChatId,
            $messageThreadId,
        );

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
}
