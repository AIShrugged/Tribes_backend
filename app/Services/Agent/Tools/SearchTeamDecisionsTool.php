<?php

namespace App\Services\Agent\Tools;

use App\Models\Decision;
use App\Models\Team;
use App\Services\Agent\Tools\Concerns\InteractsWithMcpTenant;

class SearchTeamDecisionsTool extends AbstractAgentTool
{
    use InteractsWithMcpTenant;

    public function getName(): string
    {
        return 'search_team_decisions';
    }

    public function getDescription(): string
    {
        return 'Search the team\'s decision log / knowledge base. '
            . 'Use this tool when the user asks retrospective questions: '
            . '"why", "how did we decide", "history", "who chose", "what was decided", "история", "почему", "как решили", "кто предложил". '
            . 'Returns decisions with author, date, meeting context, and topic. '
            . 'The result includes a decision_log artifact you can render for the user.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['team_id'],
            'properties' => [
                'team_id' => [
                    'type'        => 'integer',
                    'description' => 'ID of the team whose decision log to search.',
                ],
                'query' => [
                    'type'        => 'string',
                    'description' => 'Full-text search query (Russian or English). Leave empty to list recent decisions.',
                ],
                'source_type' => [
                    'type'        => 'string',
                    'enum'        => ['meeting', 'manual', 'chat'],
                    'description' => 'Filter by decision source. Omit to search all sources.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Maximum number of results per page (default: 10, max: 50).',
                ],
                'offset' => [
                    'type'        => 'integer',
                    'description' => 'How many results to skip (pagination). Use with "total"/"has_more"/"next_offset" in the response to page through ALL decisions. Defaults to 0.',
                ],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $teamId     = $parameters['team_id'] ?? null;
        $query      = trim($parameters['query'] ?? '');
        $sourceType = $parameters['source_type'] ?? null;
        $limit      = min((int) ($parameters['limit'] ?? 10), 50);
        $offset     = max(0, (int) ($parameters['offset'] ?? 0));

        if (! $teamId) {
            return ['success' => false, 'error' => 'team_id is required.'];
        }

        $user = $this->currentUser();
        if (! $user) {
            return ['success' => false, 'error' => 'Not authenticated.'];
        }

        // Security: verify caller may read the team (direct member or org manager)
        $team = Team::find((int) $teamId);
        if (! $team || ! $user->isTeamMember($team)) {
            return ['success' => false, 'error' => 'Team not found.'];
        }

        $dbQuery = Decision::where('team_id', $teamId)
            ->with(['authorUser', 'calendarEvent'])
            ->orderByDesc('created_at');

        if ($sourceType) {
            $dbQuery->where('source_type', $sourceType);
        }

        if ($query !== '') {
            $dbQuery->whereRaw(
                'search_vector @@ plainto_tsquery(\'simple\', ?)',
                [$query],
            );
        }

        $total = (clone $dbQuery)->count();

        $decisions = $dbQuery->offset($offset)->limit($limit)->get();

        $hasMore = ($offset + $decisions->count()) < $total;

        $items = $decisions->map(fn ($d) => [
            'id'          => $d->id,
            'text'        => $d->text,
            'topic'       => $d->topic,
            'source_type' => $d->source_type instanceof \BackedEnum ? $d->source_type->value : $d->source_type,
            'author'      => $d->authorUser
                ? ['id' => $d->authorUser->id, 'name' => $d->authorUser->name]
                : ['id' => null, 'name' => $d->author_raw_name],
            'meeting'     => $d->calendarEvent
                ? ['id' => $d->calendarEvent->id, 'title' => $d->calendarEvent->title, 'date' => $d->calendarEvent->starts_at]
                : null,
            'created_at'  => $d->created_at,
        ])->values()->all();

        return [
            'success'      => true,
            'team_id'      => $teamId,
            'team_name'    => $team->name,
            'count'        => count($items),
            'total'        => $total,
            'offset'       => $offset,
            'has_more'     => $hasMore,
            'next_offset'  => $hasMore ? $offset + $decisions->count() : null,
            'decisions'    => $items,
        ];
    }
}