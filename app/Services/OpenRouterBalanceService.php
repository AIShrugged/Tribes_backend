<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class OpenRouterBalanceService
{
    public function fetch(): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . config('ai.providers.openrouter.api_token'),
            'Content-Type'  => 'application/json',
        ];

        $creditsResponse = Http::withHeaders($headers)
            ->timeout(15)
            ->get('https://openrouter.ai/api/v1/credits');

        $keyResponse = Http::withHeaders($headers)
            ->timeout(15)
            ->get('https://openrouter.ai/api/v1/auth/key');

        $creditsResponse->throw();
        $keyResponse->throw();

        $credits = $creditsResponse->json('data');
        $key     = $keyResponse->json('data');

        return [
            'balance'       => round($credits['total_credits'] - $credits['total_usage'], 2),
            'usage_daily'   => round($key['usage_daily'], 2),
            'usage_weekly'  => round($key['usage_weekly'], 2),
            'usage_monthly' => round($key['usage_monthly'], 2),
        ];
    }
}
