<?php

namespace App\Services\Agent\Tools;

use App\Enums\MeetingTaskStatus;
use App\Models\CalendarEvent;
use App\Models\ChannelMessage;
use App\Models\Issue;
use App\Models\Profile;
use App\Models\User;
use App\Services\TenantScopeValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Creates a new user-facing issue, optionally linked to a meeting or chat source.
 */
class CreateIssueTool extends AbstractAgentTool
{
    private const SOURCEABLE_MAP = [
        'calendar_event' => CalendarEvent::class,
        'channel_message' => ChannelMessage::class,
        'chat_message' => ChannelMessage::class,
        'telegram_chat_message' => ChannelMessage::class,
    ];

    public function __construct(
        private readonly ?User $user = null,
        private readonly ?TenantScopeValidator $tenantScopeValidator = null,
        private readonly ?int $defaultOrganizationId = null,
        private readonly ?int $defaultTeamId = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'create_issue';
    }

    public function getDescription(): string
    {
        return 'Create a new user-facing issue. Use it for tasks or bugs that should be tracked by people, not by background agents.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Short issue name.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Detailed issue description (optional).',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => Issue::TYPES,
                    'description' => 'Issue type. Use frontend or backend for implementation work and organization for coordination or operational work.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['open', 'in_progress', 'paused', 'review', 'reopen', 'done'],
                    'description' => 'Initial issue status. Defaults to open.',
                ],
                'organization_id' => [
                    'type' => 'integer',
                    'description' => 'Organization id. Required unless the current agent context already has an organization scope.',
                ],
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional team id within the selected organization.',
                ],
                'assignee_name' => [
                    'type' => 'string',
                    'description' => 'Full name of the person responsible for the issue (optional).',
                ],
                'assignee_id' => [
                    'type' => 'integer',
                    'description' => 'User id of the assignee (optional).',
                ],
                'due_date' => [
                    'type' => 'string',
                    'description' => 'Due date in YYYY-MM-DD format (optional).',
                ],
                'sourceable_type' => [
                    'type' => 'string',
                    'enum' => ['calendar_event', 'channel_message', 'chat_message', 'telegram_chat_message'],
                    'description' => 'Optional source entity type this issue is linked to.',
                ],
                'sourceable_id' => [
                    'type' => 'integer',
                    'description' => 'Source entity id. Required when sourceable_type is provided.',
                ],
            ],
            'required' => ['name', 'type'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $name = trim((string) ($parameters['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'error' => 'name is required'];
        }

        $type = trim((string) ($parameters['type'] ?? ''));
        if (! in_array($type, Issue::TYPES, true)) {
            return ['success' => false, 'error' => 'type must be one of: frontend, backend, organization, development'];
        }

        $status = $parameters['status'] ?? MeetingTaskStatus::OPEN->value;
        $validStatuses = array_map(fn ($s) => $s->value, MeetingTaskStatus::cases());
        if (! in_array($status, $validStatuses, true)) {
            return ['success' => false, 'error' => 'status must be one of: '.implode(', ', $validStatuses)];
        }

        $sourceableType = null;
        $sourceableId = null;
        $sourceTypeKey = $parameters['sourceable_type'] ?? $parameters['taskable_type'] ?? null;

        if ($sourceTypeKey !== null) {
            if (! isset(self::SOURCEABLE_MAP[$sourceTypeKey])) {
                return ['success' => false, 'error' => 'Unknown sourceable_type: '.$sourceTypeKey];
            }

            $sourceableType = self::SOURCEABLE_MAP[$sourceTypeKey];
            $sourceableId = $parameters['sourceable_id'] ?? $parameters['taskable_id'] ?? null;

            if (! $sourceableId) {
                return ['success' => false, 'error' => 'sourceable_id is required when sourceable_type is provided'];
            }
        }

        $user = $this->resolveCurrentUser();
        if (! $user) {
            return ['success' => false, 'error' => 'Authenticated user context is required'];
        }

        $organizationId = isset($parameters['organization_id'])
            ? (int) $parameters['organization_id']
            : $this->defaultOrganizationId;
        $teamId = array_key_exists('team_id', $parameters)
            ? ($parameters['team_id'] !== null ? (int) $parameters['team_id'] : null)
            : $this->defaultTeamId;

        try {
            $this->tenantScopeValidator()->assertScopeIsValid(
                $user,
                $organizationId,
                $teamId,
                allowUnbound: false,
            );

            if ($teamId === null && ! $user->isOrganizationManager((int) $organizationId)) {
                throw ValidationException::withMessages([
                    'organization_id' => ['Only organization managers can create organization-level issues without a team scope.'],
                ]);
            }

            $assigneeId = $parameters['assignee_id'] ?? $this->resolveAssigneeId($parameters);
            $this->assertAssigneeIsVisible($user, $assigneeId !== null ? (int) $assigneeId : null);
        } catch (ValidationException $exception) {
            return [
                'success' => false,
                'error' => collect($exception->errors())->flatten()->first() ?? 'Validation failed',
            ];
        }

        $issue = Issue::create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'team_id' => $teamId,
            'sourceable_type' => $sourceableType,
            'sourceable_id' => $sourceableId,
            'assignee_id' => $assigneeId ?? null,
            'name' => $name,
            'description' => $parameters['description'] ?? null,
            'type' => $type,
            'assignee_name' => $parameters['assignee_name'] ?? null,
            'due_date' => $parameters['due_date'] ?? null,
            'status' => $status,
        ]);

        return [
            'success' => true,
            'issue' => [
                'id' => $issue->id,
                'name' => $issue->name,
                'description' => $issue->description,
                'type' => $issue->type,
                'status' => $issue->status,
                'organization_id' => $issue->organization_id,
                'team_id' => $issue->team_id,
                'assignee_name' => $issue->assignee_name,
                'assignee_id' => $issue->assignee_id,
                'due_date' => $issue->due_date?->toDateString(),
                'sourceable_type' => $issue->sourceable_type,
                'sourceable_id' => $issue->sourceable_id,
            ],
        ];
    }

    private function resolveCurrentUser(): ?User
    {
        $user = $this->user ?? Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function resolveAssigneeId(array $parameters): ?int
    {
        $profileId = $parameters['profile_id'] ?? null;

        if (! $profileId) {
            return null;
        }

        return Profile::query()->find($profileId)?->user_id;
    }

    private function assertAssigneeIsVisible(User $user, ?int $assigneeId): void
    {
        if ($assigneeId === null) {
            return;
        }

        $organizationIds = $user->organizations()->pluck('organizations.id');
        $teamIds = $user->teams()->pluck('teams.id');

        $query = User::query()->whereKey($assigneeId);
        $query->where(function (Builder $builder) use ($organizationIds, $teamIds, $user): void {
            $builder->whereKey($user->id);

            if ($organizationIds->isNotEmpty()) {
                $builder->orWhereHas('organizations', function (Builder $relation) use ($organizationIds): void {
                    $relation->whereIn('organizations.id', $organizationIds);
                });
            }

            if ($teamIds->isNotEmpty()) {
                $builder->orWhereHas('teams', function (Builder $relation) use ($teamIds): void {
                    $relation->whereIn('teams.id', $teamIds);
                });
            }
        });

        if (! $query->exists()) {
            throw ValidationException::withMessages([
                'assignee_id' => ['The selected assignee is not available to the current user.'],
            ]);
        }
    }

    private function tenantScopeValidator(): TenantScopeValidator
    {
        return $this->tenantScopeValidator ?? app(TenantScopeValidator::class);
    }
}
