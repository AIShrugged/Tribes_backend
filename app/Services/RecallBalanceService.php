<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class RecallBalanceService
{
    public function fetch(): array
    {
        $response = Http::withHeaders([
                'Authorization' => 'Token ' . config('services.recall.api_token'),
            ])
            ->timeout(15)
            ->get('https://us-west-2.recall.ai/api/v1/billing/usage/');

        $response->throw();

        return [
            'bot_total_minutes' => round($response->json('bot_total'), 1),
            'bot_total_hours'   => round($response->json('bot_total') / 60, 1),
        ];
    }
}
