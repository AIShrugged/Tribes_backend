<?php

namespace App\Services;

use App\Enums\AgentScheduleType;
use App\Exceptions\AppException;
use App\Models\AgentProfile;
use App\Models\AgentTask;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AgentTaskMutationService
{
    public function __construct(
        private readonly JsonSchemaValidationService $schemaValidation,
    ) {}

    public function preparePersistedData(
        array $data,
        User $actor,
        ?AgentTask $existingTask = null,
    ): array {
        $profileId = $data['agent_profile_id'] ?? $existingTask?->agent_profile_id;
        $profile = $profileId ? AgentProfile::query()->findOrFail($profileId) : null;

        $payload = $data['input_payload']
            ?? $existingTask?->input_payload
            ?? [];

        $this->schemaValidation->validatePayload(
            is_array($payload) ? $payload : [],
            $profile?->task_payload_schema,
        );

        $scheduleType = $data['schedule_type'] ?? $existingTask?->schedule_type?->value ?? AgentScheduleType::ONE_OFF->value;
        $intervalSeconds = array_key_exists('interval_seconds', $data)
            ? $data['interval_seconds']
            : $existingTask?->interval_seconds;

        if ($scheduleType === AgentScheduleType::INTERVAL->value) {
            if (! $intervalSeconds || (int) $intervalSeconds < 1) {
                throw new AppException(
                    'The interval_seconds field is required for interval tasks.',
                    'AGENT_TASK_INTERVAL_REQUIRED',
                    422,
                );
            }
        } else {
            $intervalSeconds = null;
        }

        $nextRunAt = array_key_exists('next_run_at', $data)
            ? $data['next_run_at']
            : $existingTask?->next_run_at;

        if ($existingTask === null && $nextRunAt === null) {
            $nextRunAt = now();
        }

        $organizationId = isset($data['organization_id']) ? (int) $data['organization_id'] : $existingTask?->organization_id;
        $teamId = array_key_exists('team_id', $data)
            ? ($data['team_id'] !== null ? (int) $data['team_id'] : null)
            : $existingTask?->team_id;

        $this->assertTenantScopeIsValid($actor, $organizationId, $teamId);

        $data['user_id'] = $existingTask?->user_id ?? $actor->id;
        $data['agent_profile_id'] = $profile?->id;
        $data['schedule_type'] = $scheduleType;
        $data['interval_seconds'] = $intervalSeconds;
        $data['next_run_at'] = $nextRunAt;

        return $data;
    }

    public function assertTenantScopeIsValid(User $user, ?int $organizationId, ?int $teamId): void
    {
        if ($organizationId === null) {
            throw ValidationException::withMessages([
                'organization_id' => ['Organization is required for agent tasks.'],
            ]);
        }

        $organization = Organization::query()->findOrFail($organizationId);
        if (! $user->isOrganizationManager($organization)) {
            throw ValidationException::withMessages([
                'organization_id' => ['Only organization managers can manage agent tasks.'],
            ]);
        }

        if ($teamId === null) {
            return;
        }

        $team = Team::query()->findOrFail($teamId);

        if ((int) $team->organization_id !== (int) $organization->id) {
            throw ValidationException::withMessages([
                'team_id' => ['Team does not belong to the selected organization.'],
            ]);
        }

        if (! $user->isTeamMember($team)) {
            throw ValidationException::withMessages([
                'team_id' => ['You do not belong to the selected team.'],
            ]);
        }
    }
}
