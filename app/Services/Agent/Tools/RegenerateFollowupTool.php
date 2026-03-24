<?php

namespace App\Services\Agent\Tools;

use App\Models\Followup;
use App\Models\User;
use App\Jobs\RegenerateFollowupJob;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Regenerates an existing AI-generated followup for the same meeting event.
 *
 * Example questions this tool answers:
 * - "Пересоздай followup для calendar event #15"
 * - "Regenerate the followup for the standup report"
 * - "Refresh the participant assessment because the methodology changed"
 */
class RegenerateFollowupTool extends AbstractAgentTool
{
    public function __construct(
        private readonly ?User $user = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'regenerate_followup';
    }

    public function getDescription(): string
    {
        return 'Regenerate the latest followup report for a calendar event using the team\'s current methodology. Use when a followup is stale, incorrect, or needs to be refreshed after methodology changes.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'calendar_event_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the calendar event whose latest followup should be regenerated.',
                ],
            ],
            'required' => ['calendar_event_id'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];
        $calendarEventId = (int) ($parameters['calendar_event_id'] ?? 0);

        if ($calendarEventId < 1) {
            return [
                'success' => false,
                'error' => 'calendar_event_id is required',
            ];
        }

        $user = $this->resolveCurrentUser();
        if (! $user) {
            return [
                'success' => false,
                'error' => 'Authenticated user context is required',
            ];
        }

        $followup = Followup::with(['calendarEvent', 'team', 'user', 'methodology'])
            ->where('calendar_event_id', $calendarEventId)
            ->owned($user->id)
            ->latest('id')
            ->first();

        if (! $followup) {
            return [
                'success' => false,
                'error' => 'Followup not found for the given calendar event or access denied',
            ];
        }

        if (! Gate::forUser($user)->allows('view', $followup)) {
            return [
                'success' => false,
                'error' => 'Followup not found for the given calendar event or access denied',
            ];
        }

        RegenerateFollowupJob::dispatch($followup->calendar_event_id, $user->id);

        return [
            'success' => true,
            'old_followup_id' => $followup->id,
            'calendar_event_id' => $followup->calendar_event_id,
            'status' => 'queued',
            'message' => 'Followup regeneration queued',
        ];
    }

    private function resolveCurrentUser(): ?User
    {
        $user = $this->user ?? Auth::user();

        return $user instanceof User ? $user : null;
    }
}
