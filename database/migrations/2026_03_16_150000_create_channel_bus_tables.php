<?php

use App\Enums\ConversationChannelType;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\ChatMessage;
use App\Models\TelegramChatMessage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('channel_type', 32);
            $table->string('conversation_key')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chat_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('telegram_chat_id')->nullable()->index();
            $table->integer('message_thread_id')->nullable();
            $table->string('title')->nullable();
            $table->timestamp('latest_message_at')->nullable();
            $table->timestamps();
            $table->index(['channel_type', 'chat_id']);
            $table->index(['channel_type', 'telegram_chat_id', 'message_thread_id']);
        });

        Schema::create('channel_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('channel_conversations')->cascadeOnDelete();
            $table->bigInteger('telegram_user_id')->nullable();
            $table->string('legacy_source_type', 64)->nullable();
            $table->unsignedBigInteger('legacy_source_id')->nullable();
            $table->string('role', 20);
            $table->string('status', 32)->default('completed');
            $table->text('content');
            $table->json('followup_data')->nullable();
            $table->text('error_message')->nullable();
            $table->string('failure_code')->nullable();
            $table->uuid('agent_run_uuid')->nullable()->index();
            $table->unsignedInteger('current_attempt')->nullable();
            $table->unsignedInteger('max_attempts')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->uuid('agent_batch_uuid')->nullable()->index();
            $table->timestamp('coalesced_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
            $table->index(['legacy_source_type', 'legacy_source_id']);
        });

        DB::table('chats')
            ->orderBy('id')
            ->each(function ($chat): void {
                DB::table('channel_conversations')->insert([
                    'channel_type' => ConversationChannelType::WEB_CHAT->value,
                    'conversation_key' => ChannelConversation::keyForChat($chat->id),
                    'user_id' => $chat->user_id,
                    'chat_id' => $chat->id,
                    'title' => $chat->title,
                    'latest_message_at' => $chat->updated_at,
                    'created_at' => $chat->created_at,
                    'updated_at' => $chat->updated_at,
                ]);
            });

        DB::table('telegram_chat_messages')
            ->select('telegram_chat_id', 'message_thread_id')
            ->distinct()
            ->orderBy('telegram_chat_id')
            ->each(function ($row): void {
                DB::table('channel_conversations')->insert([
                    'channel_type' => ConversationChannelType::TELEGRAM->value,
                    'conversation_key' => ChannelConversation::keyForTelegram(
                        (int) $row->telegram_chat_id,
                        $row->message_thread_id !== null ? (int) $row->message_thread_id : null
                    ),
                    'telegram_chat_id' => $row->telegram_chat_id,
                    'message_thread_id' => $row->message_thread_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        DB::table('chat_messages')
            ->join('channel_conversations', 'channel_conversations.chat_id', '=', 'chat_messages.chat_id')
            ->select([
                'chat_messages.*',
                'channel_conversations.id as conversation_id',
            ])
            ->orderBy('chat_messages.id')
            ->each(function ($message): void {
                DB::table('channel_messages')->insert([
                    'conversation_id' => $message->conversation_id,
                    'legacy_source_type' => 'chat_message',
                    'legacy_source_id' => $message->id,
                    'role' => $message->role,
                    'status' => $message->status ?? 'completed',
                    'content' => $message->content,
                    'followup_data' => $message->followup_data,
                    'error_message' => $message->error_message,
                    'failure_code' => $message->failure_code,
                    'agent_run_uuid' => $message->agent_run_uuid,
                    'current_attempt' => $message->current_attempt,
                    'max_attempts' => $message->max_attempts,
                    'completed_at' => $message->completed_at,
                    'next_retry_at' => $message->next_retry_at,
                    'created_at' => $message->created_at,
                    'updated_at' => $message->updated_at,
                ]);
            });

        DB::table('telegram_chat_messages')
            ->join('channel_conversations', function ($join): void {
                $join->on('channel_conversations.telegram_chat_id', '=', 'telegram_chat_messages.telegram_chat_id')
                    ->where('channel_conversations.channel_type', '=', ConversationChannelType::TELEGRAM->value)
                    ->where(function ($query): void {
                        $query->whereColumn('channel_conversations.message_thread_id', 'telegram_chat_messages.message_thread_id')
                            ->orWhere(function ($subQuery): void {
                                $subQuery->whereNull('channel_conversations.message_thread_id')
                                    ->whereNull('telegram_chat_messages.message_thread_id');
                            });
                    });
            })
            ->select([
                'telegram_chat_messages.*',
                'channel_conversations.id as conversation_id',
            ])
            ->orderBy('telegram_chat_messages.id')
            ->each(function ($message): void {
                DB::table('channel_messages')->insert([
                    'conversation_id' => $message->conversation_id,
                    'telegram_user_id' => $message->telegram_user_id,
                    'legacy_source_type' => 'telegram_chat_message',
                    'legacy_source_id' => $message->id,
                    'role' => $message->role,
                    'status' => 'completed',
                    'content' => $message->content,
                    'agent_batch_uuid' => $message->agent_batch_uuid ?? null,
                    'coalesced_at' => $message->coalesced_at ?? null,
                    'responded_at' => $message->responded_at ?? null,
                    'created_at' => $message->created_at,
                    'updated_at' => $message->updated_at,
                ]);
            });

        DB::table('tasks')
            ->where('taskable_type', ChatMessage::class)
            ->orderBy('id')
            ->each(function ($task): void {
                $channelMessageId = DB::table('channel_messages')
                    ->where('legacy_source_type', 'chat_message')
                    ->where('legacy_source_id', $task->taskable_id)
                    ->value('id');

                if ($channelMessageId) {
                    DB::table('tasks')
                        ->where('id', $task->id)
                        ->update([
                            'taskable_type' => ChannelMessage::class,
                            'taskable_id' => $channelMessageId,
                        ]);
                }
            });

        DB::table('tasks')
            ->where('taskable_type', TelegramChatMessage::class)
            ->orderBy('id')
            ->each(function ($task): void {
                $channelMessageId = DB::table('channel_messages')
                    ->where('legacy_source_type', 'telegram_chat_message')
                    ->where('legacy_source_id', $task->taskable_id)
                    ->value('id');

                if ($channelMessageId) {
                    DB::table('tasks')
                        ->where('id', $task->id)
                        ->update([
                            'taskable_type' => ChannelMessage::class,
                            'taskable_id' => $channelMessageId,
                        ]);
                }
            });

        DB::table('insight_sources')
            ->where('source_type', 'telegram')
            ->orderBy('id')
            ->each(function ($source): void {
                $channelMessageId = DB::table('channel_messages')
                    ->where('legacy_source_type', 'telegram_chat_message')
                    ->where('legacy_source_id', $source->source_id)
                    ->value('id');

                if ($channelMessageId) {
                    DB::table('insight_sources')
                        ->where('id', $source->id)
                        ->update(['source_id' => $channelMessageId]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_messages');
        Schema::dropIfExists('channel_conversations');
    }
};
