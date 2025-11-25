<?php

namespace App\Services;

use App\Domain\DTO\EventDTO;
use App\Exceptions\AppException;
use App\Models\Source;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RecallEventService implements SourceEventServiceInterface
{
    protected const API_EVENTS_URL = 'https://us-west-2.recall.ai/api/v2/calendar-events/';

    public function __construct(
        private Source $source
    ) {
    }

    /** @return EventDTO[]
     * @throws ConnectionException
     */
    public function getAllByCalendar(): array
    {
        $response = Http::withHeader('Authorization', config('services.recall.api_token'))
            ->get(static::API_EVENTS_URL, [
                'calendar_id' => $this->source->external_id,
            ]);

        $result = [];

        if (!$response->successful()) {
            throw new AppException($response->json('message'), 'RECALL_GENERIC_ERROR');
        }

        Log::info('Event data', $response->json());

        foreach ($response->json()['results'] ?? [] as $event) {
            $result[] = new EventDTO(
                $event['id'],
                $event['meeting_platform'],
                $event['start_time'],
                $event['end_time'],
                $event['meeting_url'],
                $event['raw']['summary'],
                $event['raw']['description'] ?? '',
            );
        }

        return $result;
    }
}
