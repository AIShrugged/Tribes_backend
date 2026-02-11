<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Telegram\Bot\Api;

class SetTelegramWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:set-webhook';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set Telegram bot webhook URL';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $telegram = new Api(config('telegram.bot_token'));

        $webhookUrl = 'https://5e10-45-85-105-26.ngrok-free.app' . '/api/v1/telegram/webhook';
        // $webhookUrl = config('app.url') . '/api/v1/telegram/webhook';

        $this->info("Setting webhook to: {$webhookUrl}");

        try {
            $response = $telegram->setWebhook(['url' => $webhookUrl], ['drop_pending_updates' => true]);

            if ($response) {
                $this->info('Webhook set successfully!');

                // Get webhook info
                $webhookInfo = $telegram->getWebhookInfo();
                $this->info('Webhook URL: ' . $webhookInfo->getUrl());
                $this->info('Pending updates: ' . $webhookInfo->getPendingUpdateCount());
            } else {
                $this->error('Failed to set webhook');
            }
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
        }
    }
}
