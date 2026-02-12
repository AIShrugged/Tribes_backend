<?php

namespace App\Console\Commands;

use App\Services\Insight\InsightTelegramService;
use Illuminate\Console\Command;

class InsightProcessTelegram extends Command
{
    protected $signature = 'insight:process-telegram
        {--user= : Process specific telegram_user_id only}';

    protected $description = 'Extract behavioral insights from Telegram chat history and enrich Insight profiles';

    public function handle(InsightTelegramService $service): int
    {
        $userId = $this->option('user');

        if ($userId !== null) {
            $this->info("Processing Telegram user: {$userId}");

            $processed = $service->processOne((int) $userId) ? 1 : 0;

            if ($processed) {
                $this->info('Done — insights extracted and profiles updated.');
            } else {
                $this->warn('Skipped — threshold not met, no linked account, or no insights found.');
            }

            return self::SUCCESS;
        }

        $this->info('Processing all Telegram users with linked accounts...');

        $processed = $service->processAll();

        $this->info("Done — {$processed} user(s) processed.");

        return self::SUCCESS;
    }
}
