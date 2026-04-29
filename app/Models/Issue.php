<?php

namespace App\Models;

use App\Enums\AgentTaskExecutionMode;
use App\Observers\IssueObserver;
use App\Services\IssueTypeResolver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(IssueObserver::class)]
class Issue extends Model
{
    use SoftDeletes;

    public const TYPE_DEVELOPMENT = 'development';

    public const TYPE_ORGANIZATION = 'organization';

    public const PRIORITY_CRITICAL = 500;

    public const PRIORITY_HIGH = 100;

    public const PRIORITY_NORMAL = 0;

    public const PRIORITY_LOW = -100;

    public const PRIORITY_MINIMAL = -500;

    /** @deprecated Use TYPE_DEVELOPMENT instead. Kept for backward compatibility with existing data. */
    public const TYPE_FRONTEND = 'frontend';

    /** @deprecated Use TYPE_DEVELOPMENT instead. Kept for backward compatibility with existing data. */
    public const TYPE_BACKEND = 'backend';

    public const TYPES = [
        self::TYPE_DEVELOPMENT,
        self::TYPE_ORGANIZATION,
    ];

    protected $table = 'issues';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'issue_type_id' => 'integer',
            'paperclip_user_id' => 'integer',
            'priority' => 'integer',
            'due_date' => 'date',
            'registration_date' => 'datetime',
            'close_date' => 'datetime',
            'last_agent_execution_mode' => 'string',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $issue): void {
            if (blank($issue->registration_date)) {
                $issue->registration_date = now();
            }
        });

        static::saving(function (self $issue): void {
            $resolver = app(IssueTypeResolver::class);
            $issueType = $issue->issue_type_id
                ? $issue->issueType
                : $resolver->resolveForIssue($issue);

            if ($issueType) {
                $issue->issue_type_id = $issueType->id;
                $issue->type = $issueType->key;
            } elseif (blank($issue->type)) {
                $issue->type = self::TYPE_DEVELOPMENT;
            }

            if (in_array($issue->status, ['done', 'closed', 'cancelled'], true)) {
                $issue->close_date ??= now();
            } elseif ($issue->isDirty('status')) {
                $issue->close_date = null;
            }
        });
    }

    public function scopeUrgentFor(Builder $query, int $userId): Builder
    {
        return $query
            ->withoutTrashed()
            ->whereIn('status', ['open', 'in_progress'])
            ->where(function (Builder $q) use ($userId): void {
                $q->where('user_id', $userId)->orWhere('assignee_id', $userId);
            })
            ->where(function (Builder $q): void {
                $q->where('priority', '>=', self::PRIORITY_CRITICAL)
                    ->orWhere(fn (Builder $q2) => $q2->whereNotNull('due_date')->where('due_date', '<', now()->toDateString()));
            });
    }

    public function scopeForMeeting(Builder $query, int $calendarEventId): Builder
    {
        return $query
            ->where('sourceable_type', CalendarEvent::class)
            ->where('sourceable_id', $calendarEventId);
    }

    public function scopeManual(Builder $query): Builder
    {
        return $query
            ->whereNull('sourceable_type')
            ->whereNull('sourceable_id')
            ->whereNotNull('user_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $organizationIds = $user->organizations()->pluck('organizations.id');
        $teamIds = $user->teams()->pluck('teams.id');

        return $query->where(function (Builder $builder) use ($organizationIds, $teamIds, $user): void {
            if ($organizationIds->isNotEmpty()) {
                $builder->orWhereIn('organization_id', $organizationIds);
            }

            if ($teamIds->isNotEmpty()) {
                $builder->orWhereIn('team_id', $teamIds);
            }

            $builder->orWhere(function (Builder $legacy) use ($user): void {
                $legacy->where('user_id', $user->id)
                    ->whereNull('organization_id')
                    ->whereNull('team_id');
            });

            // Assignees can always see their own tasks regardless of org/team ownership
            $builder->orWhere('assignee_id', $user->id);
        });
    }

    public function sourceable(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'sourceable_type', 'sourceable_id');
    }

    public function taskable(): MorphTo
    {
        return $this->sourceable();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paperclipUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paperclip_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function effectiveAgentProfileId(?int $explicitAgentProfileId = null): ?int
    {
        if ($explicitAgentProfileId !== null) {
            return $explicitAgentProfileId;
        }

        return $this->issueType?->agent_profile_id;
    }

    public function issueType(): BelongsTo
    {
        return $this->belongsTo(OrganizationIssueType::class, 'issue_type_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(IssueAttachment::class, 'issue_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(IssueComment::class)->whereNull('parent_id')->with(['user', 'replies.user'])->orderBy('created_at');
    }

    public static function normalizeType(?string $type): ?string
    {
        return match ($type) {
            self::TYPE_DEVELOPMENT, self::TYPE_ORGANIZATION => $type,
            self::TYPE_FRONTEND, self::TYPE_BACKEND, 'bug' => self::TYPE_DEVELOPMENT,
            'task' => self::TYPE_ORGANIZATION,
            default => null,
        };
    }

    public function isDevelopment(): bool
    {
        $baseType = $this->issueType?->base_type;

        if ($baseType !== null) {
            return $baseType === 'development';
        }

        return in_array($this->type, [self::TYPE_DEVELOPMENT, self::TYPE_FRONTEND, self::TYPE_BACKEND], true);
    }

    public function blockedBy(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(self::class, 'issue_blockers', 'blocked_id', 'blocker_id');
    }

    public function blocking(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(self::class, 'issue_blockers', 'blocker_id', 'blocked_id');
    }

    public function agentTask(): BelongsTo
    {
        return $this->belongsTo(AgentTask::class);
    }

    public function agentFlow(): HasOne
    {
        return $this->hasOne(IssueAgentFlow::class);
    }

    public function lastAgentExecutionMode(): ?AgentTaskExecutionMode
    {
        $mode = $this->last_agent_execution_mode;

        if (! is_string($mode) || $mode === '') {
            return null;
        }

        return AgentTaskExecutionMode::tryFrom($mode);
    }

    public function wasLastExecutedByPaperclip(): bool
    {
        return $this->lastAgentExecutionMode() === AgentTaskExecutionMode::PAPERCLIP
            || $this->paperclip_user_id !== null;
    }
}
