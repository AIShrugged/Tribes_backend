<?php

namespace App\Services\Agent\Tools;

use App\Enums\DecisionSourceType;
use App\Models\Decision;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class SaveTeamDecisionTool extends AbstractAgentTool
{
    public function getName(): string
    {
        return 'save_team_decision';
    }

    public function getDescription(): string
    {
        return 'Save a new decision to the team\'s knowledge base / decision log. '
            . 'Use when the user explicitly states a decision was made and wants it recorded. '
            . 'Requires explicit confirmation from the user before calling. '
            . 'The saved decision becomes searchable via search_team_decisions.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['team_id', 'text'],
            'properties' => [
                'team_id' => [
                    'type'        => 'integer',
                    'description' => 'ID of the team to save the decision for.',
                ],
                'text' => [
                    'type'        => 'string',
                    'description' => 'The decision text. Be concise and specific.',
                ],
                'topic' => [
                    'type'        => 'string',
                    'description' => 'Short topic label for the decision (3-7 words).',
                ],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $teamId = $parameters['team_id'] ?? null;
        $text   = trim($parameters['text'] ?? '');
        $topic  = trim($parameters['topic'] ?? '') ?: null;

        if (! $teamId || $text === '') {
            return ['success' => false, 'error' => 'team_id and text are required.'];
        }

        $userId = Auth::id();
        if (! $userId) {
            return ['success' => false, 'error' => 'Not authenticated.'];
        }

        // Security: verify caller is a member of the team
        $team = Team::find((int) $teamId);
        if (! $team || ! $team->users()->where('users.id', $userId)->exists()) {
            return ['success' => false, 'error' => 'Team not found.'];
        }

        // Strip control characters to prevent injection via stored text
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        if (mb_strlen($text) < 3) {
            return ['success' => false, 'error' => 'Decision text is too short.'];
        }

        $user = User::find($userId);

        $decision = Decision::create([
            'team_id'         => $team->id,
            'organization_id' => $team->organization_id,
            'source_type'     => DecisionSourceType::Chat->value,
            'text'            => $text,
            'topic'           => $topic,
            'author_user_id'  => $userId,
            'author_raw_name' => $user?->name,
        ]);

        return [
            'success'     => true,
            'decision_id' => $decision->id,
            'team_id'     => $team->id,
            'team_name'   => $team->name,
            'text'        => $decision->text,
            'topic'       => $decision->topic,
            'created_at'  => $decision->created_at,
        ];
    }
}