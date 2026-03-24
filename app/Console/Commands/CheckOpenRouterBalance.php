<?php

namespace App\Console\Commands;

use App\Services\OpenRouterBalanceService;
use Illuminate\Console\Command;
use Telegram\Bot\Api;

class CheckOpenRouterBalance extends Command
{
    protected $signature = 'openrouter:check-balance {--morning : Always send full report}';

    protected $description = 'Check OpenRouter balance and notify via Telegram';

    public function handle(OpenRouterBalanceService $service): int
    {
        $chatId    = config('ai.monitoring.telegram_chat_id');
        $threadId  = config('ai.monitoring.telegram_message_thread_id');
        $threshold = config('ai.monitoring.balance_threshold');
        $telegram  = new Api(config('telegram.bot_token'));

        try {
            $data = $service->fetch();
        } catch (\Throwable $e) {
            $this->sendMessage($telegram, $chatId, $threadId,
                "❌ *OpenRouter: ошибка проверки баланса*\n`{$e->getMessage()}`"
            );
            $this->error('Failed to fetch balance: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('morning')) {
            $this->sendMessage($telegram, $chatId, $threadId, $this->morningReport($data, $threshold));
            $this->info('Morning report sent. Balance: $' . $data['balance']);

            return self::SUCCESS;
        }

        if ($data['balance'] < $threshold) {
            $this->sendMessage($telegram, $chatId, $threadId, $this->lowBalanceAlert($data));
            $this->info('Low balance alert sent. Balance: $' . $data['balance']);
        } else {
            $this->info('Balance OK: $' . $data['balance']);
        }

        return self::SUCCESS;
    }

    private function morningReport(array $data, float $threshold): string
    {
        $balance = number_format($data['balance'], 2);
        $daily   = number_format($data['usage_daily'], 2);
        $weekly  = number_format($data['usage_weekly'], 2);
        $monthly = number_format($data['usage_monthly'], 2);

        $header = $data['balance'] < $threshold
            ? '⚠️ *OpenRouter баланс* _(низкий!)_'
            : '💰 *OpenRouter баланс*';

        return implode("\n", [
            $header,
            '━━━━━━━━━━━━━━━━━━',
            "Остаток:   *\${$balance}*",
            '━━━━━━━━━━━━━━━━━━',
            "За сутки:  \${$daily}",
            "За неделю: \${$weekly}",
            "За месяц:  \${$monthly}",
        ]);
    }

    private function lowBalanceAlert(array $data): string
    {
        $balance = number_format($data['balance'], 2);

        return "⚠️ *OpenRouter: низкий баланс*\nОстаток: *\${$balance}*";
    }

    private function sendMessage(Api $telegram, int $chatId, ?int $threadId, string $text): void
    {
        $params = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ];

        if ($threadId !== null) {
            $params['message_thread_id'] = $threadId;
        }

        try {
            $telegram->sendMessage($params);
        } catch (\Throwable) {
            unset($params['parse_mode']);
            $telegram->sendMessage($params);
        }
    }
}
