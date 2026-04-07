<?php

namespace App\Console\Commands;

use App\Services\OpenRouterBalanceService;
use App\Services\RecallBalanceService;
use Illuminate\Console\Command;
use Telegram\Bot\Api;

class CheckOpenRouterBalance extends Command
{
    protected $signature = 'openrouter:check-balance {--morning : Always send full report}';

    protected $description = 'Check OpenRouter & Recall.ai balances and notify via Telegram';

    public function handle(OpenRouterBalanceService $openRouterService, RecallBalanceService $recallService): int
    {
        $chatId    = config('ai.monitoring.telegram_chat_id');
        $threadId  = config('ai.monitoring.telegram_message_thread_id');
        $threshold = config('ai.monitoring.balance_threshold');
        $telegram  = new Api(config('telegram.bot_token'));

        // Fetch OpenRouter
        $openRouterData = null;
        $openRouterError = null;
        try {
            $openRouterData = $openRouterService->fetch();
        } catch (\Throwable $e) {
            $openRouterError = $e->getMessage();
            $this->error('Failed to fetch OpenRouter balance: ' . $openRouterError);
        }

        // Fetch Recall
        $recallData = null;
        $recallError = null;
        try {
            $recallData = $recallService->fetch();
        } catch (\Throwable $e) {
            $recallError = $e->getMessage();
            $this->error('Failed to fetch Recall usage: ' . $recallError);
        }

        if ($this->option('morning')) {
            $message = $this->morningReport($openRouterData, $openRouterError, $recallData, $recallError, $threshold);
            $this->sendMessage($telegram, $chatId, $threadId, $message);
            $this->info('Morning report sent.');

            return self::SUCCESS;
        }

        // Intraday — only alert on low OpenRouter balance
        if ($openRouterData && $openRouterData['balance'] < $threshold) {
            $this->sendMessage($telegram, $chatId, $threadId, $this->lowBalanceAlert($openRouterData));
            $this->info('Low balance alert sent. Balance: $' . $openRouterData['balance']);
        } elseif ($openRouterError) {
            $this->sendMessage($telegram, $chatId, $threadId,
                "❌ *OpenRouter: ошибка проверки баланса*\n`{$openRouterError}`"
            );
        } else {
            $this->info('Balance OK: $' . $openRouterData['balance']);
        }

        return self::SUCCESS;
    }

    private function morningReport(?array $orData, ?string $orError, ?array $recallData, ?string $recallError, float $threshold): string
    {
        $lines = [];

        // OpenRouter section
        if ($orError) {
            $lines[] = '❌ *OpenRouter: ошибка*';
            $lines[] = "`{$orError}`";
        } else {
            $balance = number_format($orData['balance'], 2);
            $daily   = number_format($orData['usage_daily'], 2);
            $weekly  = number_format($orData['usage_weekly'], 2);
            $monthly = number_format($orData['usage_monthly'], 2);

            $header = $orData['balance'] < $threshold
                ? '⚠️ *OpenRouter* _(низкий баланс!)_'
                : '💰 *OpenRouter*';

            $lines[] = $header;
            $lines[] = "Остаток:   *\${$balance}*";
            $lines[] = "За сутки:  \${$daily}";
            $lines[] = "За неделю: \${$weekly}";
            $lines[] = "За месяц:  \${$monthly}";
        }

        $lines[] = '';
        $lines[] = '━━━━━━━━━━━━━━━━━━';
        $lines[] = '';

        // Recall section
        if ($recallError) {
            $lines[] = '❌ *Recall.ai: ошибка*';
            $lines[] = "`{$recallError}`";
        } else {
            $minutes = number_format($recallData['bot_total_minutes'], 1);
            $hours   = number_format($recallData['bot_total_hours'], 1);

            $lines[] = '🎙 *Recall.ai*';
            $lines[] = "Использовано: *{$hours} ч* ({$minutes} мин)";
        }

        return implode("\n", $lines);
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
