<?php

namespace App\Console\Commands;

use App\Services\Task\TelegramTaskService;
use Illuminate\Console\Command;

class ProcessTelegramTasksCommand extends Command
{
    protected $signature = 'tasks:process-telegram
        {--chat= : Process a specific telegram_chat_id only}';

    protected $description = 'Scan recent Telegram messages for task mentions and update task statuses';

    public function handle(TelegramTaskService $service): int
    {
        $chatId = $this->option('chat');

        if ($chatId !== null) {
            $this->info("Processing Telegram chat: {$chatId}");

            $changed = $service->processChat((int) $chatId);

            if ($changed) {
                $this->info('Done — tasks created or updated.');
            } else {
                $this->info('Done — no new tasks or status changes found.');
            }

            return self::SUCCESS;
        }

        $this->info('Scanning recent Telegram chats for tasks...');

        $processed = $service->processAll();

        $this->info("Done — {$processed} chat(s) had task changes.");

        return self::SUCCESS;
    }
}
