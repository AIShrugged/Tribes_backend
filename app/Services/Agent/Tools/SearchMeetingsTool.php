<?php

namespace App\Services\Agent\Tools;

use App\Models\CalendarEvent;
use Illuminate\Support\Carbon;

class SearchMeetingsTool implements ToolInterface
{
    public function getName(): string
    {
        return 'search_meetings';
    }

    public function getDescription(): string
    {
        return 'Search for meetings/calendar events by name, date range, or user participation. Returns a list of matching events with basic details.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query to match against meeting names',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Filter meetings by user participation',
                ],
                'start_date' => [
                    'type' => 'string',
                    'description' => 'Filter meetings from this date (YYYY-MM-DD format)',
                ],
                'end_date' => [
                    'type' => 'string',
                    'description' => 'Filter meetings until this date (YYYY-MM-DD format)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results to return (default: 10)',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        // Handle null parameters
        $parameters = $parameters ?? [];

        $query = CalendarEvent::query()->with('participants');

        if (!empty($parameters['query'])) {
            $query->where('title', 'ilike', '%' . $parameters['query'] . '%');
        }

        if (!empty($parameters['user_id'])) {
            $query->whereHas('participants', function ($q) use ($parameters) {
                $q->where('profile_id', $parameters['user_id']);
            });
        }

        if (!empty($parameters['start_date'])) {
            try {
                $startDate = Carbon::parse($parameters['start_date']);
                $query->where('starts_at', '>=', $startDate);
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'error' => 'Invalid start_date format. Use YYYY-MM-DD',
                ];
            }
        }

        if (!empty($parameters['end_date'])) {
            try {
                $endDate = Carbon::parse($parameters['end_date'])->endOfDay();
                $query->where('ends_at', '<=', $endDate);
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'error' => 'Invalid end_date format. Use YYYY-MM-DD',
                ];
            }
        }

        $limit = $parameters['limit'] ?? 10;
        $events = $query->orderBy('starts_at', 'desc')->limit($limit)->get();

        return [
            'success' => true,
            'count' => $events->count(),
            'meetings' => $events->map(fn($event) => [
                'id' => $event->id,
                'title' => $event->title,
                'starts_at' => $event->starts_at,
                'ends_at' => $event->ends_at,
                'participants_count' => $event->participants->count(),
            ])->toArray(),
        ];
    }
}