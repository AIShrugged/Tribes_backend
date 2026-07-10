<?php

namespace App\Services\Agent\Tools;

use App\Enums\ConversationChannelType;
use App\Models\CalendarEvent;
use App\Models\Channel;
use App\Models\ChannelIdentity;
use App\Models\ChannelMessage;
use App\Models\Followup;
use App\Models\InsightItem;
use App\Models\InsightProfile;
use App\Models\InsightProfileHistory;
use App\Models\InsightSource;
use App\Models\Issue;
use App\Models\MeetingSummary;
use App\Models\OrganizationLink;
use App\Models\Participant;
use App\Models\Profile;
use App\Models\Team;
use App\Models\User;
use App\Services\Agent\Tools\Concerns\InteractsWithMcpTenant;
use App\Services\AgentMemoryLookupService;
use App\Services\Insight\InsightRetrievalService;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class QueryTribesDataTool extends AbstractAgentTool
{
    use InteractsWithMcpTenant;

    private const NAME_SQL = "regexp_replace(replace(lower(name), 'ё', 'е'), '\\s+', ' ', 'g')";

    private bool $actingUserResolved = false;

    private ?User $actingUserCache = null;

    private bool $orgResolved = false;

    private ?int $orgCache = null;

    /** Entities this tool can query. Exposed so the structured-query surface can merge enums. */
    public const ENTITIES = [
        'current_user', 'users', 'tasks', 'meetings', 'meeting_summary',
        'team_members', 'teams', 'organizations', 'organization_links', 'followups',
        'extracted_facts', 'user_insights', 'insight_history',
        'relationships', 'short_term_memory', 'messages', 'agent_memories',
    ];

    /** @return list<string> */
    public function supportedEntities(): array
    {
        return self::ENTITIES;
    }

    public function __construct(
        private readonly ?User $injectedUser = null,
        private readonly ?AgentMemoryLookupService $memoryLookupService = null,
        private readonly ?int $injectedOrganizationId = null,
        private readonly ?int $teamId = null,
    ) {
        parent::__construct();
    }

    /**
     * Acting user: the injected user (internal agent path) or the authenticated
     * Sanctum user (MCP path). Memoized.
     */
    private function actingUser(): ?User
    {
        if (! $this->actingUserResolved) {
            $this->actingUserCache = $this->currentUser($this->injectedUser);
            $this->actingUserResolved = true;
        }

        return $this->actingUserCache;
    }

    /**
     * Effective organization scope. On the MCP path (no injected user) it
     * defaults to the authenticated service user's single organization; on the
     * internal path it stays exactly as injected (preserving the existing
     * "explicit scope required" contract).
     */
    private function effectiveOrganizationId(): ?int
    {
        if (! $this->orgResolved) {
            $orgId = $this->injectedOrganizationId;
            if ($orgId === null && $this->injectedUser === null) {
                $orgId = $this->currentOrganizationId($this->actingUser());
            }
            $this->orgCache = $orgId;
            $this->orgResolved = true;
        }

        return $this->orgCache;
    }

    /**
     * Over MCP, deny reading insights/facts about a person outside the acting
     * user's organization. On the internal path (injected user) behaviour is
     * unchanged.
     */
    private function mcpProfileBlocked(?int $profileId): bool
    {
        if ($this->injectedUser !== null) {
            return false; // internal agent path — unchanged
        }

        return $profileId === null || ! $this->assertCanAccessProfile((int) $profileId);
    }

    public function getName(): string
    {
        return 'query_db';
    }

    public function getDescription(): string
    {
        return 'Universal read-only access to all database entities. '
            .'Set entity to choose what to query, then pass entity-specific filters. '
            .'Entities: current_user, users, tasks, meetings, meeting_summary, '
            .'team_members, teams, organizations, organization_links, followups, extracted_facts, '
            .'user_insights, insight_history, relationships, short_term_memory, '
            .'messages (type=direct|general), agent_memories. '
            .'organization_links returns the org\'s external links (e.g. GitHub repos) to inspect with git/gh '
            .'and compare against tasks/decisions.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'required' => ['entity'],
            'properties' => [
                'entity' => [
                    'type' => 'string',
                    'enum' => self::ENTITIES,
                    'description' => 'Type of data to query.',
                ],
                'filters' => [
                    'type' => 'object',
                    'description' => 'Entity-specific filters. All properties are optional.',
                    'properties' => [
                        // common
                        'user_id' => ['type' => 'integer', 'description' => 'Filter by user id.'],
                        'team_id' => ['type' => 'integer', 'description' => 'Filter by team id.'],
                        'organization_id' => ['type' => 'integer', 'description' => 'Filter by organization id.'],
                        'email' => ['type' => 'string',  'description' => 'User email (case-insensitive).'],
                        'name' => ['type' => 'string',  'description' => 'User/participant name (partial match).'],
                        'profile_id' => ['type' => 'integer', 'description' => 'Profile id for insight entities.'],
                        // tasks
                        'assignee_id' => ['type' => 'integer', 'description' => 'Filter tasks by assignee user id.'],
                        'assignee_name' => ['type' => 'string',  'description' => 'Filter tasks by assignee name (partial).'],
                        'statuses' => ['type' => 'string',  'description' => 'Tasks: comma-separated statuses (open,in_progress,paused,review,done,closed,cancelled). Default: open,in_progress. The singular `status` is also accepted here. To dedupe against completed work pass statuses:"done".'],
                        'stale_days' => ['type' => 'integer', 'description' => 'Tasks not updated for N+ days.'],
                        'calendar_event_id' => ['type' => 'integer', 'description' => 'Filter tasks/followups by meeting id.'],
                        'due_before' => ['type' => 'string',  'description' => 'Tasks due on or before (YYYY-MM-DD).'],
                        'due_after' => ['type' => 'string',  'description' => 'Tasks due on or after (YYYY-MM-DD).'],
                        'created_before' => ['type' => 'string',  'description' => 'Tasks created on or before (YYYY-MM-DD).'],
                        'created_after' => ['type' => 'string',  'description' => 'Tasks created on or after (YYYY-MM-DD).'],
                        // meetings
                        'query' => ['type' => 'string',  'description' => 'Meeting title search (partial).'],
                        'participant_name' => ['type' => 'string',  'description' => 'Filter meetings by participant name.'],
                        'start_date' => ['type' => 'string',  'description' => 'Meetings starting on/after (YYYY-MM-DD or ISO).'],
                        'end_date' => ['type' => 'string',  'description' => 'Meetings starting on/before (YYYY-MM-DD or ISO).'],
                        'order' => ['type' => 'string', 'enum' => ['starts_at_asc', 'starts_at_desc'], 'description' => 'Meeting sort order. Use starts_at_desc for the most recent meeting first.'],
                        // insights
                        'category' => [
                            'type' => 'string',
                            'enum' => ['communication_style', 'work_patterns', 'strengths', 'development_areas', 'goals_motivations', 'psychological_profile'],
                            'description' => 'Insight category filter.',
                        ],
                        'source_type' => ['type' => 'string', 'enum' => ['transcript', 'telegram'], 'description' => 'Insight source type.'],
                        'source_id' => ['type' => 'integer', 'description' => 'Source id (calendar_event_id for transcripts).'],
                        // relationships
                        'email_a' => ['type' => 'string',  'description' => 'Email of first person.'],
                        'email_b' => ['type' => 'string',  'description' => 'Email of second person.'],
                        'user_id_a' => ['type' => 'integer', 'description' => 'User id of first person.'],
                        'user_id_b' => ['type' => 'integer', 'description' => 'User id of second person.'],
                        // followups
                        'status' => ['type' => 'string', 'enum' => ['in_progress', 'done', 'failed'], 'description' => 'Followup status filter.'],
                        // messages
                        'type' => ['type' => 'string', 'enum' => ['direct', 'general'], 'description' => 'Conversation type: direct (2 participants) or general (3+).'],
                        'telegram_username' => ['type' => 'string',  'description' => 'Telegram username.'],
                        'channel_type' => ['type' => 'string', 'enum' => ['telegram', 'web_chat'], 'description' => 'Channel type for messages.'],
                        'since' => ['type' => 'string',  'description' => 'Messages since datetime.'],
                        'until' => ['type' => 'string',  'description' => 'Messages until datetime.'],
                        // agent_memories
                        'profile_key' => ['type' => 'string',  'description' => 'Agent profile key.'],
                        'task_id' => ['type' => 'integer', 'description' => 'Agent task id.'],
                        'provider' => ['type' => 'string',  'description' => 'Repository provider (e.g. github).'],
                        'owner' => ['type' => 'string',  'description' => 'Repository owner.'],
                        'repo' => ['type' => 'string',  'description' => 'Repository name.'],
                        'scope_type' => ['type' => 'string', 'enum' => ['profile', 'repository', 'task'], 'description' => 'Memory scope type.'],
                        'scope_key' => ['type' => 'string',  'description' => 'Memory scope key.'],
                        'kind' => ['type' => 'string',  'description' => 'Memory kind filter.'],
                        // team_members
                        'team_name' => ['type' => 'string', 'description' => 'Team name (alternative to team_id for team_members).'],
                    ],
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max results per page (default 20, max 200).',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'How many results to skip (pagination for tasks/meetings). Use with "total"/"has_more"/"next_offset" in the response to page through ALL results. Defaults to 0.',
                ],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        if ($this->actingUser() === null) {
            return ['success' => false, 'error' => 'Not authenticated.'];
        }

        $filters = $parameters['filters'] ?? [];
        $limit = min((int) ($parameters['limit'] ?? 20), 200);
        $offset = max(0, (int) ($parameters['offset'] ?? 0));
        $entity = (string) ($parameters['entity'] ?? '');
        $filters = $this->applyConversationScope($entity, is_array($filters) ? $filters : []);

        return match ($entity) {
            'current_user' => $this->queryCurrentUser(),
            'users' => $this->queryUsers($filters, $limit),
            'tasks' => $this->queryTasks($filters, $limit, $offset),
            'meetings' => $this->queryMeetings($filters, $limit, $offset),
            'meeting_summary' => $this->queryMeetingSummary($filters),
            'team_members' => $this->queryTeamMembers($filters),
            'teams' => $this->queryTeams($filters),
            'organizations' => $this->queryOrganizations(),
            'organization_links' => $this->queryOrganizationLinks($filters),
            'followups' => $this->queryFollowups($filters),
            'extracted_facts' => $this->queryExtractedFacts($filters),
            'user_insights' => $this->queryUserInsights($filters),
            'insight_history' => $this->queryInsightHistory($filters, $limit),
            'relationships' => $this->queryRelationships($filters),
            'short_term_memory' => $this->queryShortTermMemory($filters),
            'messages' => $this->queryMessages($filters, $limit),
            'agent_memories' => $this->queryAgentMemories($filters, $limit),
            default => ['success' => false, 'error' => 'Unknown entity: '.($parameters['entity'] ?? 'null')],
        };
    }

    private function applyConversationScope(string $entity, array $filters): array
    {
        if ($this->effectiveOrganizationId() === null) {
            return $filters;
        }

        if (in_array($entity, ['tasks', 'meetings', 'teams', 'team_members', 'organization_links'], true)) {
            $filters['organization_id'] = $this->effectiveOrganizationId();
        }

        if ($this->teamId !== null && in_array($entity, ['tasks', 'team_members'], true)) {
            $filters['team_id'] = $this->teamId;
        }

        return $filters;
    }

    // ── current_user ──────────────────────────────────────────────────────────

    private function queryCurrentUser(): array
    {
        $user = User::with([
            'organizations' => fn ($q) => $this->effectiveOrganizationId() !== null
                ? $q->where('organizations.id', $this->effectiveOrganizationId())
                : $q,
            'teams' => fn ($q) => $this->effectiveOrganizationId() !== null
                ? $q->where('teams.organization_id', $this->effectiveOrganizationId())
                : $q,
            'profiles.channel',
        ])->find($this->actingUser()->id);
        if (! $user) {
            return ['success' => false, 'error' => 'Current user not found'];
        }

        $profiles = $user->profiles;
        if ($profiles->isEmpty() && $user->email) {
            $byEmail = Profile::with('channel')->where('channel_identifier', $user->email)->first();
            if ($byEmail) {
                $profiles = collect([$byEmail]);
            }
        }

        return [
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'profiles' => $profiles->map(fn ($p) => [
                    'profile_id' => $p->id,
                    'channel' => $p->channel?->name,
                    'channel_identifier' => $p->channel_identifier,
                ])->toArray(),
                'organizations' => $user->organizations->map(fn ($org) => [
                    'id' => $org->id,
                    'name' => $org->name,
                    'role' => $org->pivot->role ?? null,
                ])->toArray(),
                'teams' => $user->teams->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                ])->toArray(),
            ],
        ];
    }

    // ── users ─────────────────────────────────────────────────────────────────

    private function queryUsers(array $filters, int $limit): array
    {
        $userId = $filters['user_id'] ?? null;
        $email = $this->normalizeEmail($filters['email'] ?? null);
        $name = $this->normalizeName($filters['name'] ?? null);

        if (! $userId && ! $email && ! $name) {
            return ['success' => false, 'error' => 'One of user_id, email, or name must be provided'];
        }

        $query = User::query()->with(['organizations', 'teams', 'profiles.channel']);
        // Always scope to a tenant — never search the global users table. With an explicit
        // org, use it; otherwise restrict to the ACTING user's accessible organizations
        // (fail-closed: no orgs → no results, rather than leaking users of other tenants).
        $scopeOrgId = $this->effectiveOrganizationId();
        if ($scopeOrgId !== null) {
            $query->whereHas('organizations', fn ($q) => $q->where('organizations.id', $scopeOrgId));
        } else {
            $actorOrgIds = $this->actingUser()?->organizations()->pluck('organizations.id')->all() ?? [];
            $query->whereHas('organizations', fn ($q) => $q->whereIn('organizations.id', $actorOrgIds));
        }

        if ($userId) {
            $user = $query->find($userId);

            return $user
                ? ['success' => true, 'user' => $this->formatUser($user)]
                : ['success' => false, 'error' => 'User not found'];
        }

        if ($email) {
            $user = $query->whereRaw("replace(lower(trim(email)), ' ', '') = ?", [$email])->first();
            if (! $user) {
                $localPart = explode('@', $email)[0] ?? $email;
                $user = (clone $query)->whereRaw("replace(lower(trim(email)), ' ', '') LIKE ?", ["{$localPart}%"])->first();
            }

            return $user
                ? ['success' => true, 'user' => $this->formatUser($user)]
                : ['success' => false, 'error' => 'User not found'];
        }

        // Match across scripts: also try the Cyrillic→Latin transliteration so a query like
        // "Иван" finds a user stored as "Ivan". NAME_SQL lowercases the stored name, and
        // NameNormalizer::normalize() lowercases + transliterates the search term.
        $terms = array_values(array_unique(array_filter([
            $name,
            \App\Support\NameNormalizer::normalize($name),
        ], fn ($t) => $t !== '')));

        $users = collect();
        foreach ($terms as $term) {
            $users = (clone $query)
                ->whereRaw(self::NAME_SQL.' ~* ?', ['\\m'.preg_quote($term, '/').'\\M'])
                ->limit($limit)
                ->get();
            if ($users->isNotEmpty()) {
                break;
            }
        }

        if ($users->isEmpty()) {
            foreach ($terms as $term) {
                $users = (clone $query)
                    ->whereRaw(self::NAME_SQL.' LIKE ?', ['%'.$term.'%'])
                    ->limit($limit)
                    ->get();
                if ($users->isNotEmpty()) {
                    break;
                }
            }
        }

        if ($users->isNotEmpty()) {
            if ($users->count() === 1) {
                return ['success' => true, 'user' => $this->formatUser($users->first())];
            }

            return [
                'success' => true,
                'multiple_matches' => true,
                'message' => "Found {$users->count()} users matching '{$name}'. Use user_id for exact lookup.",
                'users' => $users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->toArray(),
            ];
        }

        $profileIds = Participant::whereRaw(
            "regexp_replace(replace(lower(name), 'ё', 'е'), '\\s+', ' ', 'g') LIKE ?",
            ['%'.$name.'%']
        )->whereNotNull('profile_id')->pluck('profile_id')->unique()->values();

        if ($profileIds->isEmpty()) {
            return ['success' => false, 'error' => "No users found matching name '{$name}'"];
        }

        $profiles = Profile::with('channel')->whereIn('id', $profileIds)->get();
        if ($profiles->count() === 1) {
            $p = $profiles->first();

            return [
                'success' => true,
                'user' => [
                    'id' => null, 'name' => $name,
                    'profiles' => [['profile_id' => $p->id, 'channel' => $p->channel?->name]],
                    'organizations' => [], 'teams' => [],
                    'note' => 'Profile found via meeting participants. No linked user account.',
                ],
            ];
        }

        return [
            'success' => true,
            'multiple_matches' => true,
            'message' => "Found {$profiles->count()} profiles matching '{$name}'. Use profile_id for exact lookup.",
            'users' => $profiles->map(fn ($p) => ['id' => null, 'profile_id' => $p->id, 'channel' => $p->channel?->name])->toArray(),
        ];
    }

    // ── tasks ─────────────────────────────────────────────────────────────────

    private function queryTasks(array $filters, int $limit, int $offset = 0): array
    {
        $scope = $this->validateTaskTenantScope($filters);
        if ($scope['success'] === false) {
            return $scope;
        }

        $filters['organization_id'] = $scope['organization_id'];
        if ($scope['team_id'] !== null) {
            $filters['team_id'] = $scope['team_id'];
        }

        $query = Issue::query()->withoutTrashed();
        $eventId = $filters['calendar_event_id'] ?? null;

        if ($eventId) {
            $query->where('sourceable_type', CalendarEvent::class)->where('sourceable_id', $eventId);
        }

        // Accept both `statuses` (documented for tasks) and `status` (documented for
        // followups) — the LLM frequently reuses the singular key for task queries, and a
        // silent default here means it never actually sees `done`/`closed` tasks.
        $statuses = array_filter(array_map('trim', explode(',', $filters['statuses'] ?? $filters['status'] ?? 'open,in_progress')));
        $query->whereIn('status', $statuses);

        if (! empty($filters['assignee_id'])) {
            $assigneeId = (int) $filters['assignee_id'];
            $participantQ = Participant::whereHas('profile', fn ($q) => $q->where('user_id', $assigneeId));
            if ($eventId) {
                $participantQ->where('calendar_event_id', $eventId);
            }
            $assigneeNames = $participantQ->pluck('name')->unique()->values()->toArray();
            $query->where(function ($q) use ($assigneeId, $assigneeNames) {
                $q->where('assignee_id', $assigneeId);
                if (! empty($assigneeNames)) {
                    $q->orWhereIn('assignee_name', $assigneeNames);
                }
            });
        }

        if (! empty($filters['assignee_name'])) {
            $query->where('assignee_name', 'ilike', '%'.$filters['assignee_name'].'%');
        }
        if (! empty($filters['team_id'])) {
            $query->where('team_id', $filters['team_id']);
        }
        if (! empty($filters['organization_id'])) {
            $query->inOrganization((int) $filters['organization_id']);
        }
        if (! empty($filters['stale_days'])) {
            $query->where('updated_at', '<=', Carbon::now()->subDays($filters['stale_days']));
        }
        if (! empty($filters['due_before'])) {
            $query->where('due_date', '<=', Carbon::parse($filters['due_before'])->toDateString());
        }
        if (! empty($filters['due_after'])) {
            $query->where('due_date', '>=', Carbon::parse($filters['due_after'])->toDateString());
        }
        if (! empty($filters['created_before'])) {
            $query->where('created_at', '<=', $this->parseDateBoundary($filters['created_before'], endOfDay: true));
        }
        if (! empty($filters['created_after'])) {
            $query->where('created_at', '>=', $this->parseDateBoundary($filters['created_after'], endOfDay: false));
        }

        $total = (clone $query)->count();
        $tasks = $query->orderByRaw('due_date ASC NULLS LAST')->offset($offset)->limit($limit)->get();
        $hasMore = ($offset + $tasks->count()) < $total;
        if ($tasks->isEmpty()) {
            return [
                'success' => true,
                'tasks_count' => 0,
                'total' => $total,
                'offset' => $offset,
                'has_more' => $hasMore,
                'next_offset' => $hasMore ? $offset + $tasks->count() : null,
                'message' => $total > 0
                    ? 'No tasks on this page; offset is past the end. Lower the offset.'
                    : 'No tasks found matching the filters.',
            ];
        }

        $now = Carbon::now();

        // Resolve assignee_profile_id in one batch query
        $assigneeIds = $tasks->pluck('assignee_id')->filter()->unique()->values();
        $assigneeProfileIds = $assigneeIds->isNotEmpty()
            ? Profile::whereIn('user_id', $assigneeIds)->get()->groupBy('user_id')->map(fn ($g) => $g->first()->id)
            : collect();

        // Batch-load source meetings for tasks created from CalendarEvents
        $meetingSourceIds = $tasks
            ->filter(fn ($t) => $t->sourceable_type === CalendarEvent::class)
            ->pluck('sourceable_id')->filter()->unique()->values();
        $sourceMeetings = $meetingSourceIds->isNotEmpty()
            ? CalendarEvent::whereIn('id', $meetingSourceIds)->get()->keyBy('id')
            : collect();

        return [
            'success' => true,
            'tasks_count' => $tasks->count(),
            'total' => $total,
            'offset' => $offset,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + $tasks->count() : null,
            'tasks' => $tasks->map(function ($t) use ($now, $assigneeProfileIds, $sourceMeetings) {
                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'description' => $t->description ? mb_substr($t->description, 0, 300) : null,
                    'status' => $t->status,
                    'assignee_name' => $t->assignee_name,
                    'assignee_id' => $t->assignee_id,
                    'assignee_profile_id' => $t->assignee_id ? ($assigneeProfileIds[$t->assignee_id] ?? null) : null,
                    'due_date' => $t->due_date?->toDateString(),
                    'created_at' => $t->created_at?->toDateString(),
                    'team_id' => $t->team_id,
                    'organization_id' => $t->organization_id,
                    'days_since_update' => (int) abs($now->diffInDays($t->updated_at)),
                    'sourceable_id' => $t->sourceable_id,
                    'source_meeting' => ($t->sourceable_type === CalendarEvent::class && $t->sourceable_id)
                        ? (function () use ($t, $sourceMeetings) {
                            $ev = $sourceMeetings[$t->sourceable_id] ?? null;

                            return $ev ? [
                                'calendar_event_id' => $ev->id,
                                'title' => $ev->title,
                                'starts_at' => $ev->starts_at ? Carbon::parse($ev->starts_at)->toIso8601String() : null,
                            ] : null;
                        })()
                        : null,
                    'created_at' => $t->created_at->toDateString(),
                ];
            })->toArray(),
        ];
    }

    private function parseDateBoundary(string $value, bool $endOfDay): Carbon
    {
        $date = Carbon::parse($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1) {
            return $endOfDay ? $date->endOfDay() : $date->startOfDay();
        }

        return $date;
    }

    private function validateTaskTenantScope(array $filters): array
    {
        $orgId = isset($filters['organization_id']) && $filters['organization_id'] !== ''
            ? (int) $filters['organization_id']
            : null;
        $teamId = isset($filters['team_id']) && $filters['team_id'] !== ''
            ? (int) $filters['team_id']
            : null;

        if ($orgId === null && $teamId === null) {
            return [
                'success' => false,
                'error' => 'organization_id or team_id is required for task queries.',
            ];
        }

        if ($teamId !== null) {
            $team = Team::query()->find($teamId);
            if (! $team) {
                return ['success' => false, 'error' => "Team {$teamId} not found."];
            }

            if ($orgId !== null && (int) $team->organization_id !== $orgId) {
                return ['success' => false, 'error' => 'team_id does not belong to organization_id.'];
            }

            $orgId ??= (int) $team->organization_id;

            if (! $this->actingUser()->isTeamMember($team)) {
                return ['success' => false, 'error' => 'You do not have access to this team.'];
            }
        }

        if ($orgId !== null && ! $this->actingUser()->isOrganizationMember($orgId)) {
            return ['success' => false, 'error' => 'You do not have access to this organization.'];
        }

        return [
            'success' => true,
            'organization_id' => $orgId,
            'team_id' => $teamId,
        ];
    }

    // ── meetings ──────────────────────────────────────────────────────────────

    private function queryMeetings(array $filters, int $limit, int $offset = 0): array
    {
        // Reject filters this entity does not honor — otherwise they are silently dropped and
        // the agent gets a result that doesn't match its intent (e.g. asking "completed" and
        // receiving future meetings). organization_id is injected by applyConversationScope.
        $supported = ['organization_id', 'query', 'user_id', 'participant_name', 'start_date', 'end_date', 'order'];
        $unknown = array_diff(array_keys($filters), $supported);
        if ($unknown !== []) {
            return [
                'success' => false,
                'error' => 'Unsupported filter(s) for meetings: '.implode(', ', $unknown)
                    .'. Supported: '.implode(', ', $supported)
                    .'. Use start_date/end_date for time and order=starts_at_desc for the latest meeting.',
            ];
        }

        $userId = Auth::id();

        $query = CalendarEvent::query()
            ->where(function ($q) use ($userId) {
                $q->whereHas('sources', fn ($s) => $s->where('user_id', $userId))
                    ->orWhereHas('profiles', fn ($p) => $p->where('user_id', $userId))
                    ->orWhereHas('participants.profile', fn ($p) => $p->where('user_id', $userId));
            })
            ->with('participants');

        if (! empty($filters['organization_id'])) {
            // calendar_events has NO organization_id column — a meeting's org comes from its
            // source (see CalendarEventOrganizationResolver). Scope via the source relationship.
            $orgId = (int) $filters['organization_id'];
            $query->whereHas('sources', fn ($s) => $s->where('sources.organization_id', $orgId));
        }
        if (! empty($filters['query'])) {
            $query->where('title', 'ilike', '%'.$filters['query'].'%');
        }
        if (! empty($filters['user_id'])) {
            $query->whereHas('participants', fn ($q) => $q->where('profile_id', $filters['user_id']));
        }
        if (! empty($filters['participant_name'])) {
            $query->whereHas('participants', fn ($q) => $q->where('name', 'ilike', '%'.$filters['participant_name'].'%'));
        }
        if (! empty($filters['start_date'])) {
            try {
                $query->where('starts_at', '>=', Carbon::parse($filters['start_date']));
            } catch (\Exception) {
                return ['success' => false, 'error' => 'Invalid start_date format.'];
            }
        }
        if (! empty($filters['end_date'])) {
            try {
                $endDate = Carbon::parse($filters['end_date']);
                if (! str_contains($filters['end_date'], ':')) {
                    $endDate = $endDate->endOfDay();
                }
                $query->where('starts_at', '<=', $endDate);
            } catch (\Exception) {
                return ['success' => false, 'error' => 'Invalid end_date format.'];
            }
        }

        $direction = (($filters['order'] ?? null) === 'starts_at_desc') ? 'desc' : 'asc';
        $total = (clone $query)->count();
        $events = $query->orderBy('starts_at', $direction)->offset($offset)->limit($limit)->get();
        $hasMore = ($offset + $events->count()) < $total;

        return [
            'success' => true,
            'count' => $events->count(),
            'total' => $total,
            'offset' => $offset,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + $events->count() : null,
            'meetings' => $events->map(fn ($e) => [
                'id' => $e->id,
                'title' => $e->title,
                'starts_at' => $e->starts_at,
                'ends_at' => $e->ends_at,
                'participants' => $e->participants->map(fn ($p) => [
                    'name' => $p->name,
                    'profile_id' => $p->profile_id,
                ])->toArray(),
            ])->toArray(),
        ];
    }

    // ── meeting_summary ───────────────────────────────────────────────────────

    private function queryMeetingSummary(array $filters): array
    {
        $eventId = $filters['calendar_event_id'] ?? null;
        if (! $eventId) {
            return ['success' => false, 'error' => 'calendar_event_id is required for meeting_summary'];
        }

        $summary = MeetingSummary::with('calendarEvent.participants')
            ->where('calendar_event_id', $eventId)
            ->first();

        if (! $summary) {
            return ['success' => false, 'error' => 'No summary found for this meeting. The meeting might not have been processed yet.'];
        }
        if ($summary->status === 'in_progress') {
            return ['success' => false, 'error' => 'Meeting summary is still being generated. Please try again later.'];
        }
        if ($summary->status === 'failed') {
            return ['success' => false, 'error' => 'Meeting summary generation failed for this meeting.'];
        }

        $event = $summary->calendarEvent;
        $participants = $event?->participants->map(fn ($p) => array_filter([
            'name' => $p->name,
            'profile_id' => $p->profile_id ?? null,
        ], fn ($v) => $v !== null))->toArray() ?? [];

        $commitments = $summary->commitments ?? [];

        $relatedTasks = Issue::withoutTrashed()->forMeeting($eventId)->get();
        $meetingAssigneeIds = $relatedTasks->pluck('assignee_id')->filter()->unique()->values();
        $meetingAssigneeProfileIds = $meetingAssigneeIds->isNotEmpty()
            ? Profile::whereIn('user_id', $meetingAssigneeIds)->get()
                ->groupBy('user_id')->map(fn ($g) => $g->first()->id)
            : collect();
        $relatedTasksArray = $relatedTasks->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'status' => $t->status,
            'assignee_name' => $t->assignee_name,
            'assignee_profile_id' => $t->assignee_id ? ($meetingAssigneeProfileIds[$t->assignee_id] ?? null) : null,
            'due_date' => $t->due_date?->toDateString(),
        ])->toArray();

        return [
            'success' => true,
            'calendar_event_id' => $eventId,
            'title' => $summary->title,
            'starts_at' => $event?->starts_at ? Carbon::parse($event->starts_at)->toIso8601String() : null,
            'ends_at' => $event?->ends_at ? Carbon::parse($event->ends_at)->toIso8601String() : null,
            'participants' => $participants,
            'summary' => $summary->summary,
            'key_points' => $summary->key_points ?? [],
            'decisions' => $summary->decisions ?? [],
            'commitments' => $commitments,
            'related_tasks' => $relatedTasksArray,
        ];
    }

    // ── team_members ──────────────────────────────────────────────────────────

    private function queryTeamMembers(array $filters): array
    {
        $teamId = $filters['team_id'] ?? null;
        $teamName = $filters['team_name'] ?? null;
        $organizationId = $filters['organization_id'] ?? null;

        if (! $teamId && ! $teamName) {
            return ['success' => false, 'error' => 'Either team_id or team_name must be provided'];
        }

        $query = Team::query()->with(['users', 'organization']);
        if ($organizationId) {
            $query->where('organization_id', (int) $organizationId);
        }
        $team = $teamId
            ? $query->find($teamId)
            : $query->where('name', 'ilike', "%{$teamName}%")->first();

        if (! $team) {
            $suggestions = Team::where('name', 'ilike', '%'.mb_substr($teamName ?? '', 0, 3).'%')
                ->limit(5)->pluck('name')->toArray();

            return ['success' => false, 'error' => 'Team not found', 'suggestions' => $suggestions];
        }

        $memberIds = $team->users->pluck('id');
        $profileMap = Profile::whereIn('user_id', $memberIds)
            ->orderBy('id')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($g) => $g->first()->id);

        $members = $team->users->map(fn ($u) => [
            'user_id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'profile_id' => $profileMap[$u->id] ?? null,
        ])->toArray();

        return [
            'success' => true,
            '_hint' => 'profile_id is available for each member. To get roles, responsibilities, or what each person does — call get_user_insights(profile_id) for each member.',
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'organization' => $team->organization
                    ? ['id' => $team->organization->id, 'name' => $team->organization->name]
                    : null,
                'member_count' => count($members),
            ],
            'members' => $members,
        ];
    }

    // ── teams ─────────────────────────────────────────────────────────────────

    private function queryTeams(array $filters): array
    {
        $organizationId = $filters['organization_id'] ?? null;
        if (! $organizationId) {
            return ['success' => false, 'error' => 'organization_id is required for teams query'];
        }
        if (! $this->actingUser()->isOrganizationMember($organizationId)) {
            return ['success' => false, 'error' => 'You are not a member of this organization.'];
        }

        $teams = Team::where('organization_id', $organizationId)->get(['id', 'name', 'methodology_id']);

        return [
            'success' => true,
            'teams' => $teams->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'current_methodology_id' => $t->methodology_id,
            ])->values()->toArray(),
        ];
    }

    // ── organizations ─────────────────────────────────────────────────────────

    private function queryOrganizations(): array
    {
        $organizations = $this->actingUser()->organizations()
            ->when(
                $this->effectiveOrganizationId() !== null,
                fn ($q) => $q->where('organizations.id', $this->effectiveOrganizationId())
            )
            ->get(['organizations.id', 'organizations.name']);

        return [
            'success' => true,
            'organizations' => $organizations->map(fn ($org) => [
                'id' => $org->id,
                'name' => $org->name,
            ])->values()->toArray(),
        ];
    }

    // ── organization_links ────────────────────────────────────────────────────

    private function queryOrganizationLinks(array $filters): array
    {
        $orgId = isset($filters['organization_id']) && $filters['organization_id'] !== ''
            ? (int) $filters['organization_id']
            : $this->effectiveOrganizationId();

        if ($orgId === null) {
            return ['success' => false, 'error' => 'organization_id is required for organization_links'];
        }
        if (! $this->actingUser()->isOrganizationMember($orgId)) {
            return ['success' => false, 'error' => 'You are not a member of this organization.'];
        }

        $links = OrganizationLink::with('context')
            ->where('organization_id', $orgId)
            ->orderBy('id')
            ->get();

        return [
            'success' => true,
            'organization_id' => $orgId,
            'count' => $links->count(),
            '_hint' => 'Inspect each url with git/gh (merged PRs, recent commits) and compare against open tasks/decisions to find done-but-open work or lost agreements.',
            'links' => $links->map(fn (OrganizationLink $l) => [
                'id' => $l->id,
                'url' => $l->url,
                'indexed_context' => $l->context?->text ? mb_substr($l->context->text, 0, 1000) : null,
                'indexed_at' => $l->context?->indexed_at ? Carbon::parse($l->context->indexed_at)->toIso8601String() : null,
                'created_at' => $l->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    // ── followups ─────────────────────────────────────────────────────────────

    private function queryFollowups(array $filters): array
    {
        $eventId = $filters['calendar_event_id'] ?? null;
        if (! $eventId) {
            return ['success' => false, 'error' => 'calendar_event_id is required for followups'];
        }

        $query = Followup::with(['user', 'team', 'methodology'])->where('calendar_event_id', $eventId);
        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $followups = $query->get();
        if ($followups->isEmpty()) {
            return [
                'success' => true,
                'calendar_event_id' => $eventId,
                'followups_count' => 0,
                'message' => 'No followups found. They might still be processing or have not been generated yet.',
            ];
        }

        return [
            'success' => true,
            'calendar_event_id' => $eventId,
            'followups_count' => $followups->count(),
            'followups' => $followups->map(fn ($f) => [
                'id' => $f->id,
                'user_id' => $f->user_id,
                'user_name' => $f->user?->name,
                'team_id' => $f->team_id,
                'team_name' => $f->team?->name,
                'methodology_id' => $f->methodology_id,
                'methodology' => $f->methodology?->name,
                'status' => $f->status,
                'text' => $this->decodeText($f->text),
            ])->toArray(),
        ];
    }

    // ── extracted_facts ───────────────────────────────────────────────────────

    private function queryExtractedFacts(array $filters): array
    {
        $profileId = $filters['profile_id'] ?? null;
        if (! $profileId) {
            return ['success' => false, 'error' => 'profile_id is required for extracted_facts'];
        }
        if ($this->mcpProfileBlocked((int) $profileId)) {
            return ['success' => false, 'error' => 'Profile not accessible.'];
        }

        $sourcesQuery = InsightSource::where('profile_id', $profileId);
        if (! empty($filters['source_type'])) {
            $sourcesQuery->where('source_type', $filters['source_type']);
        }
        if (! empty($filters['source_id'])) {
            $sourcesQuery->where('source_id', $filters['source_id']);
        }

        $sources = $sourcesQuery->get();
        if ($sources->isEmpty()) {
            return [
                'success' => false,
                'error' => 'No extracted facts found for this person.'
                    .(! empty($filters['source_id']) ? ' The source might not have been processed yet.' : ''),
            ];
        }

        $itemsQuery = InsightItem::whereIn('insight_source_id', $sources->pluck('id'))->where('is_archived', false);
        if (! empty($filters['category'])) {
            $itemsQuery->where('category', $filters['category']);
        }

        $items = $itemsQuery->orderBy('confidence', 'desc')->get();
        if ($items->isEmpty()) {
            return ['success' => true, 'profile_id' => $profileId, 'facts_count' => 0, 'message' => 'No facts found matching your filters.'];
        }

        $itemsBySource = $items->groupBy('insight_source_id');
        $bySource = $sources->map(function ($source) use ($itemsBySource) {
            $sourceItems = $itemsBySource->get($source->id, collect());
            if ($sourceItems->isEmpty()) {
                return null;
            }

            return [
                'source_type' => $source->source_type,
                'source_id' => $source->source_id,
                'processed_at' => $source->processed_at?->toIso8601String(),
                'facts_by_category' => $sourceItems->groupBy('category')->map(fn ($ci) => $ci->map(fn ($item) => ['fact' => $item->fact, 'confidence' => $item->confidence])->toArray()
                )->toArray(),
            ];
        })->filter()->values()->toArray();

        return ['success' => true, 'profile_id' => $profileId, 'facts_count' => $items->count(), 'sources' => $bySource];
    }

    // ── user_insights ─────────────────────────────────────────────────────────

    private function queryUserInsights(array $filters): array
    {
        $userId = $filters['user_id'] ?? null;
        $email = $this->normalizeEmail($filters['email'] ?? null);
        $profileId = $filters['profile_id'] ?? null;

        if (! $userId && ! $email && ! $profileId) {
            return ['success' => false, 'error' => 'One of user_id, email, or profile_id must be provided'];
        }

        if ($profileId) {
            $profile = Profile::find($profileId);
            if (! $profile) {
                return ['success' => false, 'error' => 'Profile not found'];
            }
        } else {
            $user = $userId
                ? User::find($userId)
                : User::whereRaw("replace(lower(trim(email)), ' ', '') = ?", [$email])->first();
            if (! $user) {
                return ['success' => false, 'error' => 'User not found'];
            }
            $profile = $this->resolveProfile($user);
        }

        if (! $profile) {
            return ['success' => true, 'data' => null, 'message' => 'No insight profile found for this user'];
        }
        if ($this->mcpProfileBlocked($profile->id)) {
            return ['success' => false, 'error' => 'Profile not accessible.'];
        }

        return ['success' => true, 'data' => app(InsightRetrievalService::class)->getFullProfile($profile->id)];
    }

    // ── insight_history ───────────────────────────────────────────────────────

    private function queryInsightHistory(array $filters, int $limit): array
    {
        $profileId = $filters['profile_id'] ?? null;
        $category = $filters['category'] ?? null;
        $limit = min($limit, 50);

        if (! $profileId) {
            return ['success' => false, 'error' => 'profile_id is required for insight_history'];
        }
        if ($this->mcpProfileBlocked((int) $profileId)) {
            return ['success' => false, 'error' => 'Profile not accessible.'];
        }

        $insightProfileIds = InsightProfile::where('profile_id', $profileId)
            ->when($category, fn ($q) => $q->where('category', $category))
            ->pluck('id');

        if ($insightProfileIds->isEmpty()) {
            return ['success' => false, 'profile_id' => $profileId, 'error' => 'No insight profile found for this person.'];
        }

        $history = InsightProfileHistory::whereIn('insight_profile_id', $insightProfileIds)
            ->when($category, fn ($q) => $q->where('category', $category))
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        if ($history->isEmpty()) {
            return ['success' => true, 'profile_id' => $profileId, 'history_count' => 0, 'message' => 'No history entries found.'];
        }

        return [
            'success' => true,
            'profile_id' => $profileId,
            'history_count' => $history->count(),
            'history' => $history->map(fn ($e) => [
                'version' => $e->version,
                'category' => $e->category,
                'content' => $e->content,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->toArray(),
        ];
    }

    // ── relationships ─────────────────────────────────────────────────────────

    private function queryRelationships(array $filters): array
    {
        $profileIdA = $this->resolveProfileIdFor($filters['email_a'] ?? null, $filters['user_id_a'] ?? null);
        $profileIdB = $this->resolveProfileIdFor($filters['email_b'] ?? null, $filters['user_id_b'] ?? null);

        if (! $profileIdA || ! $profileIdB) {
            return ['success' => false, 'error' => 'Could not resolve insight profiles. Provide email_a/email_b or user_id_a/user_id_b.'];
        }
        if ($this->mcpProfileBlocked($profileIdA) || $this->mcpProfileBlocked($profileIdB)) {
            return ['success' => false, 'error' => 'Profile not accessible.'];
        }

        $relationship = app(InsightRetrievalService::class)->getRelationship($profileIdA, $profileIdB);
        if (! $relationship) {
            return ['success' => true, 'data' => null, 'message' => 'No relationship data found between these two people'];
        }

        return ['success' => true, 'data' => $relationship];
    }

    // ── short_term_memory ─────────────────────────────────────────────────────

    private function queryShortTermMemory(array $filters): array
    {
        $userId = $filters['user_id'] ?? null;
        $email = $this->normalizeEmail($filters['email'] ?? null);

        if (! $userId && ! $email) {
            return ['success' => false, 'error' => 'Either user_id or email must be provided'];
        }

        $user = $userId
            ? User::find($userId)
            : User::whereRaw("replace(lower(trim(email)), ' ', '') = ?", [$email])->first();

        if (! $user) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $profile = $this->resolveProfile($user);
        if ($profile && $this->mcpProfileBlocked($profile->id)) {
            return ['success' => false, 'error' => 'Profile not accessible.'];
        }
        $shortTerm = $profile ? app(InsightRetrievalService::class)->getShortTermContext($profile->id) : [];

        return [
            'success' => true,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'short_term_context' => $shortTerm,
        ];
    }

    // ── messages ──────────────────────────────────────────────────────────────

    private function queryMessages(array $filters, int $limit): array
    {
        $resolvedUser = $this->resolveUserForMessages($filters);
        if (($resolvedUser['success'] ?? false) !== true) {
            return $resolvedUser;
        }

        /** @var User $user */
        $user = $resolvedUser['user'];
        $channelType = (string) ($filters['channel_type'] ?? ConversationChannelType::TELEGRAM->value);
        $msgLimit = max(1, min($limit, 100));
        $convType = $filters['type'] ?? 'direct';

        if ($convType === 'direct' && $user->id !== $this->actingUser()->id) {
            return [
                'success' => false,
                'error' => 'Direct/private messages are only visible to their owner.',
            ];
        }

        [$operator, $value] = $convType === 'general' ? ['>=', 3] : ['=', 2];

        $query = ChannelMessage::query()
            ->with([
                'authorIdentity:id,user_id,external_id,display_name,username',
                'conversation:id,channel_type,conversation_key,title,telegram_chat_id,message_thread_id',
            ])
            ->where('role', 'user')
            ->whereHas('authorIdentity', fn ($q) => $q->where('user_id', $user->id))
            ->whereHas('conversation', fn ($q) => $q->where('channel_type', $channelType))
            ->whereIn('conversation_id', function (QueryBuilder $sub) use ($operator, $value): void {
                $sub->from('channel_conversation_participants')
                    ->select('conversation_id')
                    ->groupBy('conversation_id')
                    ->havingRaw("COUNT(*) {$operator} ?", [$value]);
            });

        if ($this->effectiveOrganizationId() !== null && $convType === 'general') {
            $query->whereHas('conversation', fn ($q) => $q->where('organization_id', $this->effectiveOrganizationId()));
        }

        if (! empty($filters['since'])) {
            try {
                $since = Carbon::parse((string) $filters['since']);
                if (! str_contains((string) $filters['since'], ':')) {
                    $since = $since->startOfDay();
                }
                $query->where('created_at', '>=', $since);
            } catch (\Throwable) {
                return ['success' => false, 'error' => 'Invalid since date format.'];
            }
        }
        if (! empty($filters['until'])) {
            try {
                $until = Carbon::parse((string) $filters['until']);
                if (! str_contains((string) $filters['until'], ':')) {
                    $until = $until->endOfDay();
                }
                $query->where('created_at', '<=', $until);
            } catch (\Throwable) {
                return ['success' => false, 'error' => 'Invalid until date format.'];
            }
        }

        $messages = $query->orderBy('created_at', 'desc')->limit($msgLimit)->get()->reverse()->values();

        return [
            'success' => true,
            'conversation_kind' => $convType,
            'resolved_user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'count' => $messages->count(),
            'messages' => $messages->map(fn (ChannelMessage $m) => [
                'id' => $m->id,
                'content' => $m->content,
                'created_at' => $m->created_at?->toDateTimeString(),
                'author' => [
                    'display_name' => $m->authorIdentity?->display_name,
                    'username' => $m->authorIdentity?->username,
                    'external_id' => $m->authorIdentity?->external_id,
                ],
                'conversation' => [
                    'id' => $m->conversation?->id,
                    'channel_type' => $m->conversation?->channel_type?->value ?? $m->conversation?->channel_type,
                    'conversation_key' => $m->conversation?->conversation_key,
                    'title' => $m->conversation?->title,
                    'telegram_chat_id' => $m->conversation?->telegram_chat_id,
                    'message_thread_id' => $m->conversation?->message_thread_id,
                ],
            ])->all(),
        ];
    }

    // ── agent_memories ────────────────────────────────────────────────────────

    private function queryAgentMemories(array $filters, int $limit): array
    {
        try {
            $memoryLookup = $this->memoryLookupService ?? app(AgentMemoryLookupService::class);
            $memories = $memoryLookup->searchAccessibleMemories($this->actingUser()->id, $filters, $limit);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return [
            'success' => true,
            'count' => $memories->count(),
            'memories' => $memories->map(fn ($m) => [
                'id' => $m->id,
                'profile' => ['id' => $m->profile?->id, 'key' => $m->profile?->key, 'name' => $m->profile?->name],
                'scope_type' => $m->scope_type,
                'scope_key' => $m->scope_key,
                'kind' => $m->kind,
                'content' => $m->content,
                'priority' => $m->priority,
                'last_seen_at' => $m->last_seen_at?->toISOString(),
                'source_task_id' => $m->metadata['source_task_id'] ?? null,
                'source_run_id' => $m->metadata['source_run_id'] ?? null,
            ])->values()->all(),
        ];
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function formatUser(User $user): array
    {
        $profiles = $user->profiles;
        if ($profiles->isEmpty() && $user->email) {
            $byEmail = Profile::with('channel')->where('channel_identifier', $user->email)->first();
            if ($byEmail) {
                $profiles = collect([$byEmail]);
            }
        }

        // Tenant isolation: only expose orgs/teams the requesting user also belongs to
        $requestingOrgIds = $this->actingUser()->organizations()
            ->when(
                $this->effectiveOrganizationId() !== null,
                fn ($q) => $q->where('organizations.id', $this->effectiveOrganizationId())
            )
            ->pluck('organizations.id')
            ->toArray();
        $visibleOrgs = $user->organizations->filter(fn ($org) => in_array($org->id, $requestingOrgIds));
        $hasSharedOrg = $visibleOrgs->isNotEmpty();
        $visibleOrgIds = $visibleOrgs->pluck('id')->all();

        return [
            'id' => $user->id,
            'name' => $user->name,
            // email visible only when users share at least one organization
            'email' => $hasSharedOrg ? $user->email : null,
            'profiles' => $profiles->map(fn ($p) => [
                'profile_id' => $p->id,
                'channel' => $p->channel?->name,
                'channel_identifier' => $p->channel_identifier,
            ])->toArray(),
            'organizations' => $visibleOrgs->map(fn ($org) => [
                'id' => $org->id,
                'name' => $org->name,
                'role' => $org->pivot->role ?? null,
            ])->values()->toArray(),
            'teams' => $user->teams
                ->filter(fn ($t) => in_array($t->organization_id, $visibleOrgIds, true))
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])
                ->values()
                ->toArray(),
        ];
    }

    private function resolveProfile(User $user): ?Profile
    {
        $profile = Profile::where('user_id', $user->id)->first();
        if (! $profile) {
            $gcChannelId = Channel::idFor('google_calendar');
            if ($gcChannelId) {
                $profile = Profile::where('channel_id', $gcChannelId)
                    ->where('channel_identifier', $user->email)
                    ->first();
            }
        }

        return $profile;
    }

    private function resolveProfileIdFor(?string $email, ?int $userId): ?int
    {
        if ($userId) {
            $user = User::find($userId);
            if ($user) {
                $profile = Profile::where('user_id', $user->id)->first();
                if ($profile) {
                    return $profile->id;
                }
                $email = $email ?? $user->email;
            }
        }
        if ($email) {
            $gcChannelId = Channel::idFor('google_calendar');
            if ($gcChannelId) {
                $profile = Profile::where('channel_id', $gcChannelId)->where('channel_identifier', $email)->first();
                if ($profile) {
                    return $profile->id;
                }
            }
        }

        return null;
    }

    private function resolveUserForMessages(array $filters): array
    {
        $userId = $filters['user_id'] ?? null;
        $email = $this->normalizeEmail($filters['email'] ?? null);
        $name = $this->normalizeName($filters['name'] ?? null);
        $telegramUsername = $this->normalizeTelegramUsername($filters['telegram_username'] ?? null);

        if (! $userId && ! $email && ! $name && ! $telegramUsername) {
            return ['success' => false, 'error' => 'One of user_id, email, name, or telegram_username must be provided.'];
        }

        if ($userId) {
            $user = User::query()->find($userId);

            return $user ? ['success' => true, 'user' => $user] : ['success' => false, 'error' => 'User not found.'];
        }
        if ($email) {
            $user = User::query()->whereRaw("replace(lower(trim(email)), ' ', '') = ?", [$email])->first();
            if (! $user) {
                $localPart = explode('@', $email)[0] ?? $email;
                $user = User::query()->whereRaw("replace(lower(trim(email)), ' ', '') LIKE ?", ["{$localPart}%"])->first();
            }

            return $user ? ['success' => true, 'user' => $user] : ['success' => false, 'error' => 'User not found.'];
        }
        if ($telegramUsername) {
            $identity = ChannelIdentity::query()
                ->where('channel_type', ConversationChannelType::TELEGRAM->value)
                ->whereRaw("replace(lower(trim(username)), ' ', '') = ?", [$telegramUsername])
                ->first();
            if (! $identity) {
                $identity = ChannelIdentity::query()
                    ->where('channel_type', ConversationChannelType::TELEGRAM->value)
                    ->whereRaw("replace(lower(trim(username)), ' ', '') LIKE ?", ['%'.$telegramUsername.'%'])
                    ->first();
            }
            if (! $identity?->user_id) {
                return ['success' => false, 'error' => 'Telegram username not found or not linked to an application user.'];
            }
            $user = User::query()->find($identity->user_id);

            return $user ? ['success' => true, 'user' => $user] : ['success' => false, 'error' => 'User not found.'];
        }

        $users = User::query()
            ->whereRaw(self::NAME_SQL.' LIKE ?', ['%'.$name.'%'])
            ->limit(5)->get();

        if ($users->isEmpty()) {
            return ['success' => false, 'error' => "No users found matching '{$name}'."];
        }
        if ($users->count() > 1) {
            return [
                'success' => false,
                'error' => "Multiple users match '{$name}'. Use user_id or email.",
                'matches' => $users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->all(),
            ];
        }

        return ['success' => true, 'user' => $users->first()];
    }

    private function decodeText(mixed $text): mixed
    {
        if (! is_string($text)) {
            return $text;
        }
        $decoded = json_decode($text, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $text;
    }

    private function normalizeEmail(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $v = preg_replace('/\s+/', '', mb_strtolower(trim($value))) ?? '';

        return $v !== '' ? $v : null;
    }

    private function normalizeName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $v = trim($value);
        $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
        $v = str_replace('ё', 'е', mb_strtolower($v));

        return $v !== '' ? $v : null;
    }

    private function normalizeTelegramUsername(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $v = ltrim(mb_strtolower(trim($value)), '@');
        $v = preg_replace('/\s+/', '', $v) ?? $v;

        return $v !== '' ? $v : null;
    }
}
