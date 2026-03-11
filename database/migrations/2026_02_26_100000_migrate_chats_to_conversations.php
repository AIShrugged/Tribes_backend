<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration: move existing web chats and Telegram messages
 * into the unified conversations / conversation_participants / messages tables.
 *
 * Source tables (read-only, not dropped here):
 *   chats, chat_messages, telegram_chat_messages
 *
 * Destination tables:
 *   conversations, conversation_participants, messages
 */
return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------------
        // 1. Web chats: chats → conversations + participants + messages
        // ----------------------------------------------------------------
        $chats = DB::table('chats')->get();

        foreach ($chats as $chat) {
            // Skip if already migrated (idempotent)
            $alreadyExists = DB::table('conversations')
                ->where('channel_type', 'web')
                ->whereNull('external_id')
                ->where('metadata->legacy_chat_id', $chat->id)
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            // Create conversation
            $conversationId = DB::table('conversations')->insertGetId([
                'channel_type' => 'web',
                'external_id'  => null,
                'title'        => $chat->title,
                'status'       => 'active',
                'metadata'     => json_encode(['legacy_chat_id' => $chat->id]),
                'created_at'   => $chat->created_at,
                'updated_at'   => $chat->updated_at,
            ]);

            // Add owner participant
            DB::table('conversation_participants')->insertOrIgnore([
                'conversation_id'      => $conversationId,
                'participantable_type' => 'App\Models\User',
                'participantable_id'   => $chat->user_id,
                'role'                 => 'owner',
                'joined_at'            => $chat->created_at,
                'created_at'           => $chat->created_at,
                'updated_at'           => $chat->created_at,
            ]);

            // Migrate messages for this chat
            $chatMessages = DB::table('chat_messages')
                ->where('chat_id', $chat->id)
                ->orderBy('id')
                ->get();

            foreach ($chatMessages as $msg) {
                $isUser      = $msg->role === 'user';
                $followupRaw = $msg->followup_data ?? null;
                $metadata    = [];

                if ($followupRaw !== null) {
                    $decoded = is_string($followupRaw) ? json_decode($followupRaw, true) : $followupRaw;
                    if ($decoded !== null) {
                        $metadata['followup_data'] = $decoded;
                    }
                }

                DB::table('messages')->insert([
                    'conversation_id' => $conversationId,
                    'role'            => $msg->role,
                    'content'         => $msg->content,
                    'sender_type'     => $isUser ? 'App\Models\User' : null,
                    'sender_id'       => $isUser ? $chat->user_id : null,
                    'source_msg_id'   => null,
                    'metadata'        => json_encode($metadata),
                    'created_at'      => $msg->created_at,
                    'updated_at'      => $msg->updated_at,
                ]);
            }
        }

        // ----------------------------------------------------------------
        // 2. Telegram chats: telegram_chat_messages → conversations + participants + messages
        // ----------------------------------------------------------------

        // Group by unique telegram_chat_id
        $uniqueChatIds = DB::table('telegram_chat_messages')
            ->select('telegram_chat_id')
            ->distinct()
            ->pluck('telegram_chat_id');

        foreach ($uniqueChatIds as $telegramChatId) {
            $channelType = $telegramChatId < 0 ? 'telegram_group' : 'telegram_private';
            $externalId  = (string) $telegramChatId;

            // Skip if already migrated
            $alreadyExists = DB::table('conversations')
                ->where('channel_type', $channelType)
                ->where('external_id', $externalId)
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            // Timestamps from first and last message
            $firstMsg = DB::table('telegram_chat_messages')
                ->where('telegram_chat_id', $telegramChatId)
                ->orderBy('id')
                ->first();

            $lastMsg = DB::table('telegram_chat_messages')
                ->where('telegram_chat_id', $telegramChatId)
                ->orderByDesc('id')
                ->first();

            $conversationId = DB::table('conversations')->insertGetId([
                'channel_type' => $channelType,
                'external_id'  => $externalId,
                'title'        => null,
                'status'       => 'active',
                'metadata'     => json_encode([]),
                'created_at'   => $firstMsg->created_at,
                'updated_at'   => $lastMsg->updated_at,
            ]);

            // Add unique participants (non-null telegram_user_ids)
            $participantIds = DB::table('telegram_chat_messages')
                ->where('telegram_chat_id', $telegramChatId)
                ->whereNotNull('telegram_user_id')
                ->select('telegram_user_id')
                ->distinct()
                ->pluck('telegram_user_id');

            foreach ($participantIds as $telegramUserId) {
                // Resolve joined_at from first message by this user in this chat
                $firstUserMsg = DB::table('telegram_chat_messages')
                    ->where('telegram_chat_id', $telegramChatId)
                    ->where('telegram_user_id', $telegramUserId)
                    ->orderBy('id')
                    ->first();

                DB::table('conversation_participants')->insertOrIgnore([
                    'conversation_id'      => $conversationId,
                    'participantable_type' => 'App\Models\TelegramUser',
                    'participantable_id'   => $telegramUserId,
                    'role'                 => 'member',
                    'joined_at'            => $firstUserMsg->created_at,
                    'created_at'           => $firstUserMsg->created_at,
                    'updated_at'           => $firstUserMsg->created_at,
                ]);
            }

            // Migrate messages
            $telegramMessages = DB::table('telegram_chat_messages')
                ->where('telegram_chat_id', $telegramChatId)
                ->orderBy('id')
                ->get();

            foreach ($telegramMessages as $msg) {
                $hasSender = $msg->telegram_user_id !== null;

                DB::table('messages')->insert([
                    'conversation_id' => $conversationId,
                    'role'            => $msg->role,
                    'content'         => $msg->content,
                    'sender_type'     => $hasSender ? 'App\Models\TelegramUser' : null,
                    'sender_id'       => $hasSender ? $msg->telegram_user_id : null,
                    'source_msg_id'   => null,
                    'metadata'        => json_encode([]),
                    'created_at'      => $msg->created_at,
                    'updated_at'      => $msg->updated_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Remove only the conversations created by this migration
        // (identified by the legacy_chat_id in metadata for web chats,
        //  and by existing external_ids for telegram chats)
        $legacyChatIds = DB::table('chats')->pluck('id');
        $telegramChatIds = DB::table('telegram_chat_messages')
            ->select('telegram_chat_id')
            ->distinct()
            ->pluck('telegram_chat_id')
            ->map(fn ($id) => (string) $id);

        // Delete web conversations that were migrated from chats
        foreach ($legacyChatIds as $legacyChatId) {
            $conv = DB::table('conversations')
                ->where('channel_type', 'web')
                ->whereNull('external_id')
                ->whereRaw("metadata::text LIKE ?", ['%"legacy_chat_id":' . $legacyChatId . '%'])
                ->first();

            if ($conv) {
                DB::table('messages')->where('conversation_id', $conv->id)->delete();
                DB::table('conversation_participants')->where('conversation_id', $conv->id)->delete();
                DB::table('conversations')->where('id', $conv->id)->delete();
            }
        }

        // Delete telegram conversations
        foreach ($telegramChatIds as $externalId) {
            $conv = DB::table('conversations')
                ->whereIn('channel_type', ['telegram_private', 'telegram_group'])
                ->where('external_id', $externalId)
                ->first();

            if ($conv) {
                DB::table('messages')->where('conversation_id', $conv->id)->delete();
                DB::table('conversation_participants')->where('conversation_id', $conv->id)->delete();
                DB::table('conversations')->where('id', $conv->id)->delete();
            }
        }
    }
};
