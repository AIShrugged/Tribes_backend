<?php

namespace App\Models;

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

    public const TYPE_FRONTEND = 'frontend';

    public const TYPE_BACKEND = 'backend';

    public const TYPE_ORGANIZATION = 'organization';

    public const TYPE_DEVELOPMENT = 'development';

    public const TYPES = [
        self::TYPE_FRONTEND,
        self::TYPE_BACKEND,
        self::TYPE_ORGANIZATION,
        self::TYPE_DEVELOPMENT,
    ];

    protected $table = 'issues';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'issue_type_id' => 'integer',
            'due_date' => 'date',
            'registration_date' => 'datetime',
            'close_date' => 'datetime',
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
                $issue->type = self::TYPE_ORGANIZATION;
            }

            if ($issue->status === 'done') {
                $issue->close_date ??= now();
            } elseif ($issue->isDirty('status')) {
                $issue->close_date = null;
            }
        });
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
            self::TYPE_FRONTEND, self::TYPE_BACKEND, self::TYPE_ORGANIZATION => $type,
            self::TYPE_DEVELOPMENT, 'bug' => self::TYPE_BACKEND,
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

        return in_array($this->type, [self::TYPE_FRONTEND, self::TYPE_BACKEND, self::TYPE_DEVELOPMENT], true);
    }

    public function agentTask(): BelongsTo
    {
        return $this->belongsTo(AgentTask::class);
    }

    public function agentFlow(): HasOne
    {
        return $this->hasOne(IssueAgentFlow::class);
    }
}
