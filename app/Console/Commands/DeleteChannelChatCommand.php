<?php

namespace App\Console\Commands;

use App\Models\AgentActivityLog;
use App\Models\AgentMemory;
use App\Models\AgentTask;
use App\Models\ChannelConversation;
use App\Models\ChannelConversationParticipant;
use App\Models\ChannelMessage;
use App\Models\ConversationCompactionSnapshot;
use App\Models\TelegramChatRegistration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class DeleteChannelChatCommand extends Command
{
    protected $signature = 'channel:delete-chat
        {--chat= : Telegram chat ID to delete (use --chat= to support negative IDs, e.g. --chat=-1001234567)}
        {--dry-run : Show what would be deleted without making changes}';

    protected $description = 'Delete a Telegram chat conversation and all related data';

    public function handle(): int
    {
        $chatOption = $this->option('chat');

        if ($chatOption === null) {
            $this->error('Please provide --chat=<telegram_chat_id>');

            return self::FAILURE;
        }

        $telegramChatId = (int) $chatOption;
        $dryRun = (bool) $this->option('dry-run');

        $conversations = ChannelConversation::query()
            ->where('telegram_chat_id', $telegramChatId)
            ->get();

        if ($conversations->isEmpty()) {
            $this->warn("No conversations found for telegram_chat_id={$telegramChatId}.");

            return self::FAILURE;
        }

        $conversationIds = $conversations->pluck('id')->all();
        $conversationKeys = $conversations->pluck('conversation_key')->all();

        $agentRunUuids = ChannelMessage::query()
            ->whereIn('conversation_id', $conversationIds)
            ->whereNotNull('agent_run_uuid')
            ->pluck('agent_run_uuid')
            ->unique()
            ->values()
            ->all();

        $messagesCount = ChannelMessage::query()->whereIn('conversation_id', $conversationIds)->count();
        $participantsCount = ChannelConversationParticipant::query()->whereIn('conversation_id', $conversationIds)->count();
        $registrationsCount = TelegramChatRegistration::query()->whereIn('channel_conversation_id', $conversationIds)->count();
        $activityLogsCount = $agentRunUuids
            ? AgentActivityLog::query()->whereIn('agent_run_uuid', $agentRunUuids)->count()
            : 0;
        $compactionSnapshotsCount = ConversationCompactionSnapshot::query()
            ->whereIn('conversation_key', $conversationKeys)
            ->count();
        $agentMemoriesCount = AgentMemory::query()
            ->whereIn('scope_key', $conversationKeys)
            ->count();
        $agentTasksCount = AgentTask::query()
            ->where('notification_telegram_chat_id', $telegramChatId)
            ->count();

        $this->info("Found for telegram_chat_id={$telegramChatId}:");
        $this->line("  Conversations:             {$conversations->count()}");
        $this->line("  Messages:                  {$messagesCount}");
        $this->line("  Participants:              {$participantsCount}");
        $this->line("  Registrations:             {$registrationsCount}");
        $this->line("  Agent activity logs:       {$activityLogsCount}");
        $this->line("  Compaction snapshots:      {$compactionSnapshotsCount}");
        $this->line("  Agent memories:            {$agentMemoriesCount}");
        $this->line("  Agent tasks (notify only): {$agentTasksCount} (will clear chat reference, not deleted)");
        $this->newLine();
        $this->line('Cache keys to flush:');
        foreach ($conversationKeys as $key) {
            $this->line("  telegram_typing:{$key}");
            $this->line("  telegram_typing_last_sent:{$key}");
        }
        foreach ($conversations as $conv) {
            $threadId = $conv->message_thread_id ?? 'root';
            $this->line("  telegram_coalesce:{$telegramChatId}:{$threadId}");
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('Dry run — no changes made.');

            return self::SUCCESS;
        }

        $this->newLine();
        if (! $this->confirm("Delete all of the above for telegram_chat_id={$telegramChatId}?")) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        // Cache
        foreach ($conversationKeys as $key) {
            Cache::forget("telegram_typing:{$key}");
            Cache::forget("telegram_typing_last_sent:{$key}");
        }
        foreach ($conversations as $conv) {
            $threadId = $conv->message_thread_id ?? 'root';
            Cache::forget("telegram_coalesce:{$telegramChatId}:{$threadId}");
        }

        // DB
        if ($agentRunUuids) {
            AgentActivityLog::query()->whereIn('agent_run_uuid', $agentRunUuids)->delete();
        }

        ConversationCompactionSnapshot::query()
            ->whereIn('conversation_key', $conversationKeys)
            ->delete();

        AgentMemory::query()
            ->whereIn('scope_key', $conversationKeys)
            ->delete();

        AgentTask::query()
            ->where('notification_telegram_chat_id', $telegramChatId)
            ->update([
                'notification_telegram_chat_id' => null,
                'notification_telegram_thread_id' => null,
            ]);

        ChannelMessage::query()->whereIn('conversation_id', $conversationIds)->delete();
        ChannelConversationParticipant::query()->whereIn('conversation_id', $conversationIds)->delete();
        TelegramChatRegistration::query()->whereIn('channel_conversation_id', $conversationIds)->delete();
        ChannelConversation::query()->whereIn('id', $conversationIds)->delete();

        $this->info('Done.');

        return self::SUCCESS;
    }
}
