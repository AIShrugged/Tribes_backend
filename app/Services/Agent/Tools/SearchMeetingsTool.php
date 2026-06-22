<?php

namespace App\Services\Agent\Tools;

use App\Models\CalendarEvent;
use App\Services\Agent\Tools\Concerns\InteractsWithMcpTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class SearchMeetingsTool extends AbstractAgentTool
{
    use InteractsWithMcpTenant;

    public function getName(): string
    {
        return 'search_meetings';
    }

    public function getDescription(): string
    {
        return 'Search for meetings/calendar events by name, date range, participant name, or user participation. '
            . 'Returns a list of matching events sorted chronologically (oldest first) with participant names. '
            . 'Use start_date + end_date with the same value to search meetings for a specific day. '
            . 'Datetime format is supported: "2026-02-18 14:00:00" or "2026-02-18T14:00:00" or just "2026-02-18".';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query to match against meeting titles (case-insensitive partial match)',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Filter meetings where this user (by user_id) participated',
                ],
                'participant_name' => [
                    'type' => 'string',
                    'description' => 'Filter meetings where a participant with this name took part (partial, case-insensitive)',
                ],
                'start_date' => [
                    'type' => 'string',
                    'description' => 'Show meetings that start on or after this datetime. Accepts "YYYY-MM-DD", "YYYY-MM-DD HH:MM:SS", or ISO 8601.',
                ],
                'end_date' => [
                    'type' => 'string',
                    'description' => 'Show meetings that start on or before this datetime (end of day if only date given). Accepts "YYYY-MM-DD", "YYYY-MM-DD HH:MM:SS", or ISO 8601.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results per page (default: 10, max: 50)',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'How many meetings to skip (pagination). Use with "total"/"has_more"/"next_offset" in the response to page through ALL meetings. Defaults to 0.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        // Handle null parameters
        $parameters = $parameters ?? [];

        $user = $this->currentUser();
        if (! $user) {
            return ['success' => false, 'error' => 'Not authenticated.'];
        }

        $orgIds = $this->currentOrganizationIds($user);

        // Scope to the acting user's own meetings PLUS any meeting visible to one
        // of their organizations (a per-org service user owns no meetings itself).
        $query = CalendarEvent::query()
            ->where(function (Builder $scope) use ($user, $orgIds): void {
                $scope->owned($user->id);
                foreach ($orgIds as $orgId) {
                    $scope->orWhere(fn (Builder $inner) => $inner->visibleToOrganization($orgId));
                }
            })
            ->with('participants');

        if (!empty($parameters['query'])) {
            $query->where('title', 'ilike', '%' . $parameters['query'] . '%');
        }

        if (!empty($parameters['user_id'])) {
            $query->whereHas('participants', function ($q) use ($parameters) {
                $q->where('profile_id', $parameters['user_id']);
            });
        }

        if (!empty($parameters['participant_name'])) {
            $query->whereHas('participants', function ($q) use ($parameters) {
                $q->where('name', 'ilike', '%' . $parameters['participant_name'] . '%');
            });
        }

        if (!empty($parameters['start_date'])) {
            try {
                $startDate = Carbon::parse($parameters['start_date']);
                $query->where('starts_at', '>=', $startDate);
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'error' => 'Invalid start_date format. Use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS',
                ];
            }
        }

        if (!empty($parameters['end_date'])) {
            try {
                $endDate = Carbon::parse($parameters['end_date']);
                // If only a date was provided (no time component), extend to end of day
                if (!str_contains($parameters['end_date'], ':')) {
                    $endDate = $endDate->endOfDay();
                }
                // Filter by starts_at so we capture meetings that STARTED within the range
                $query->where('starts_at', '<=', $endDate);
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'error' => 'Invalid end_date format. Use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS',
                ];
            }
        }

        $limit = min((int) ($parameters['limit'] ?? 10), 50);
        $offset = max(0, (int) ($parameters['offset'] ?? 0));

        $total = (clone $query)->count();
        // Sort ascending (chronological) so "first/second meeting" references work naturally
        $events = $query->orderBy('starts_at', 'asc')->offset($offset)->limit($limit)->get();

        $hasMore = ($offset + $events->count()) < $total;

        return [
            'success' => true,
            'count' => $events->count(),
            'total' => $total,
            'offset' => $offset,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + $events->count() : null,
            'meetings' => $events->map(fn($event) => [
                'id' => $event->id,
                'title' => $event->title,
                'starts_at' => $event->starts_at,
                'ends_at' => $event->ends_at,
                'participants' => $event->participants->map(fn($p) => [
                    'name' => $p->name,
                    'profile_id' => $p->profile_id,
                ])->toArray(),
            ])->toArray(),
        ];
    }
}