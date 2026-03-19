<?php

namespace App\Services\Agent\Tools;

use App\Enums\ConversationChannelType;
use App\Models\ChannelIdentity;
use App\Models\ChannelMessage;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

abstract class AbstractUserChannelMessagesTool extends AbstractAgentTool
{
    private const NAME_NORMALIZATION_SQL = "regexp_replace(replace(lower(name), 'ё', 'е'), '\\s+', ' ', 'g')";

    abstract protected function participantCountOperator(): string;

    abstract protected function participantCountValue(): int;

    abstract protected function conversationKind(): string;

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Target application user id.',
                ],
                'email' => [
                    'type' => 'string',
                    'description' => 'Target user email. Case and spaces are ignored.',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Target user name. Case, repeated spaces, and е/ё differences are ignored.',
                ],
                'telegram_username' => [
                    'type' => 'string',
                    'description' => 'Target Telegram username. Leading @, case, and spaces are ignored.',
                ],
                'channel_type' => [
                    'type' => 'string',
                    'enum' => [
                        ConversationChannelType::TELEGRAM->value,
                        ConversationChannelType::WEB_CHAT->value,
                    ],
                    'description' => 'Optional channel filter. Defaults to telegram.',
                ],
                'since' => [
                    'type' => 'string',
                    'description' => 'Get messages starting from this datetime. Accepts YYYY-MM-DD, YYYY-MM-DD HH:MM:SS, or ISO 8601.',
                ],
                'until' => [
                    'type' => 'string',
                    'description' => 'Get messages until this datetime. End of day is used when only a date is provided.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of messages to return (default 20, max 100).',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $resolvedUser = $this->resolveTargetUser($parameters);
        if (($resolvedUser['success'] ?? false) !== true) {
            return $resolvedUser;
        }

        /** @var User $user */
        $user = $resolvedUser['user'];
        $channelType = (string) ($parameters['channel_type'] ?? ConversationChannelType::TELEGRAM->value);
        $limit = max(1, min((int) ($parameters['limit'] ?? 20), 100));
        $allowedChannelTypes = array_map(
            static fn (ConversationChannelType $type) => $type->value,
            ConversationChannelType::cases(),
        );

        if (! in_array($channelType, $allowedChannelTypes, true)) {
            return [
                'success' => false,
                'error' => 'Invalid channel_type. Allowed values: telegram, web_chat.',
            ];
        }

        $query = ChannelMessage::query()
            ->with([
                'authorIdentity:id,user_id,external_id,display_name,username',
                'conversation:id,channel_type,conversation_key,title,telegram_chat_id,message_thread_id',
            ])
            ->where('role', 'user')
            ->whereHas('authorIdentity', function ($identityQuery) use ($user): void {
                $identityQuery->where('user_id', $user->id);
            })
            ->whereHas('conversation', function ($conversationQuery) use ($channelType): void {
                $conversationQuery->where('channel_type', $channelType);
            })
            ->whereIn('conversation_id', function (QueryBuilder $subQuery): void {
                $subQuery->from('channel_conversation_participants')
                    ->select('conversation_id')
                    ->groupBy('conversation_id')
                    ->havingRaw(
                        sprintf('COUNT(*) %s ?', $this->participantCountOperator()),
                        [$this->participantCountValue()]
                    );
            });

        if (! empty($parameters['since'])) {
            try {
                $since = Carbon::parse((string) $parameters['since']);
                if (! str_contains((string) $parameters['since'], ':')) {
                    $since = $since->startOfDay();
                }

                $query->where('created_at', '>=', $since);
            } catch (\Throwable) {
                return [
                    'success' => false,
                    'error' => 'Invalid since date format. Use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.',
                ];
            }
        }

        if (! empty($parameters['until'])) {
            try {
                $until = Carbon::parse((string) $parameters['until']);
                if (! str_contains((string) $parameters['until'], ':')) {
                    $until = $until->endOfDay();
                }

                $query->where('created_at', '<=', $until);
            } catch (\Throwable) {
                return [
                    'success' => false,
                    'error' => 'Invalid until date format. Use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.',
                ];
            }
        }

        $messages = $query
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        return [
            'success' => true,
            'conversation_kind' => $this->conversationKind(),
            'resolved_user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'count' => $messages->count(),
            'messages' => $messages->map(function (ChannelMessage $message): array {
                return [
                    'id' => $message->id,
                    'content' => $message->content,
                    'created_at' => $message->created_at?->toDateTimeString(),
                    'author' => [
                        'display_name' => $message->authorIdentity?->display_name,
                        'username' => $message->authorIdentity?->username,
                        'external_id' => $message->authorIdentity?->external_id,
                    ],
                    'conversation' => [
                        'id' => $message->conversation?->id,
                        'channel_type' => $message->conversation?->channel_type?->value ?? $message->conversation?->channel_type,
                        'conversation_key' => $message->conversation?->conversation_key,
                        'title' => $message->conversation?->title,
                        'telegram_chat_id' => $message->conversation?->telegram_chat_id,
                        'message_thread_id' => $message->conversation?->message_thread_id,
                    ],
                ];
            })->all(),
        ];
    }

    private function resolveTargetUser(array $parameters): array
    {
        $userId = $parameters['user_id'] ?? null;
        $email = $this->normalizeEmailInput($parameters['email'] ?? null);
        $name = $this->normalizeLooseText($parameters['name'] ?? null);
        $telegramUsername = $this->normalizeTelegramUsername($parameters['telegram_username'] ?? null);

        if (! $userId && ! $email && ! $name && ! $telegramUsername) {
            return [
                'success' => false,
                'error' => 'One of user_id, email, name, or telegram_username must be provided.',
            ];
        }

        if ($userId) {
            $user = User::query()->find($userId);

            return $user
                ? ['success' => true, 'user' => $user]
                : ['success' => false, 'error' => 'User not found.'];
        }

        if ($email) {
            $user = User::query()
                ->whereRaw("replace(lower(trim(email)), ' ', '') = ?", [$email])
                ->first();

            if (! $user) {
                $localPart = explode('@', $email)[0] ?? $email;
                $user = User::query()
                    ->whereRaw("replace(lower(trim(email)), ' ', '') LIKE ?", ["{$localPart}%"])
                    ->first();
            }

            return $user
                ? ['success' => true, 'user' => $user]
                : ['success' => false, 'error' => 'User not found.'];
        }

        if ($telegramUsername) {
            $identity = ChannelIdentity::query()
                ->where('channel_type', ConversationChannelType::TELEGRAM->value)
                ->whereRaw("replace(lower(trim(username)), ' ', '') = ?", [$telegramUsername])
                ->first();

            if (! $identity) {
                $identity = ChannelIdentity::query()
                    ->where('channel_type', ConversationChannelType::TELEGRAM->value)
                    ->whereRaw("replace(lower(trim(username)), ' ', '') LIKE ?", ['%'.$telegramUsername.'%'])
                    ->first();
            }

            if (! $identity?->user_id) {
                return [
                    'success' => false,
                    'error' => 'Telegram username not found or not linked to an application user.',
                ];
            }

            $user = User::query()->find($identity->user_id);

            return $user
                ? ['success' => true, 'user' => $user]
                : ['success' => false, 'error' => 'User not found.'];
        }

        $users = User::query()
            ->whereRaw(self::NAME_NORMALIZATION_SQL.' LIKE ?', ['%'.$name.'%'])
            ->limit(5)
            ->get();

        if ($users->isEmpty()) {
            return [
                'success' => false,
                'error' => sprintf("No users found matching '%s'.", $name),
            ];
        }

        if ($users->count() > 1) {
            return [
                'success' => false,
                'error' => sprintf("Multiple users match '%s'. Use user_id or email.", $name),
                'matches' => $users->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])->all(),
            ];
        }

        return [
            'success' => true,
            'user' => $users->first(),
        ];
    }

    private function normalizeEmailInput(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));
        $normalized = preg_replace('/\s+/', '', $normalized) ?? $normalized;

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeTelegramUsername(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));
        $normalized = ltrim($normalized, '@');
        $normalized = preg_replace('/\s+/', '', $normalized) ?? $normalized;

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeLooseText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = str_replace('ё', 'е', mb_strtolower($normalized));

        return $normalized !== '' ? $normalized : null;
    }
}
