<?php

namespace App\Services;

use App\Domain\DTO\OauthDTO;
use App\Domain\DTO\SourceDTO;
use App\Enums\SourceType;
use App\Exceptions\AppException;
use Illuminate\Support\Facades\Http;

class RecallCalendarService
{
    protected const API_URL = 'https://us-west-2.recall.ai/api/v2/calendars/';

    public static function attach(OauthDTO $oauthDTO, SourceType $type): SourceDTO
    {
        $response = Http::withHeader('Authorization', config('services.recall.api_token'))
            ->post(self::API_URL, [
                'oauth_client_id'     => config('services.google.client_id'),
                'oauth_client_secret' => config('services.google.client_secret'),
                'oauth_refresh_token' => $oauthDTO->refreshToken,
                'platform'            => $type->value,
            ]);

        if (!$response->successful()) {
            throw new AppException($response->json()["message"], 'RECALL_GENERIC_ERROR');
        }

        return new SourceDTO($response['id'], $oauthDTO->email, $type->value);
    }

    public static function reAttach(OauthDTO $oauthDTO, SourceType $type, string $externalId): SourceDTO
    {
        $response = Http::withHeader('Authorization', config('services.recall.api_token'))
            ->patch(self::API_URL . $externalId, [
                'oauth_client_id'     => config('services.google.client_id'),
                'oauth_client_secret' => config('services.google.client_secret'),
                'oauth_refresh_token' => $oauthDTO->refreshToken,
                'platform'            => $type->value,
            ]);

        if (!$response->successful()) {
            throw new AppException($response->json()["message"], 'RECALL_GENERIC_ERROR');
        }

        return new SourceDTO($response['id'], $oauthDTO->email, $type->value);
    }

    public static function isConnected(string $calendarId): bool
    {
        $response = Http::withHeader('Authorization', config('services.recall.api_token'))
            ->get(self::API_URL . $calendarId);

        if (!$response->successful()) {
            throw new AppException($response->json()["message"], 'RECALL_GENERIC_ERROR');
        }

        return $response->json()['status'] == 'connected';
    }
}
