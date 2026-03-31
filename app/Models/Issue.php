<?php

namespace App\Models;

use App\Observers\IssueObserver;
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

    public const TYPES = [
        self::TYPE_DEVELOPMENT,
        self::TYPE_ORGANIZATION,
    ];

    protected $table = 'issues';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
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
        $managerOrganizationIds = $user->organizations()
            ->wherePivot('role', 'manager')
            ->pluck('organizations.id');
        $memberOrganizationIds = $user->organizations()
            ->pluck('organizations.id');
        $teamIds = $user->teams()->pluck('teams.id');

        return $query->where(function (Builder $builder) use ($managerOrganizationIds, $memberOrganizationIds, $teamIds, $user): void {
            if ($managerOrganizationIds->isNotEmpty()) {
                $builder->orWhereIn('organization_id', $managerOrganizationIds);
            }

            if ($teamIds->isNotEmpty()) {
                $builder->orWhereIn('team_id', $teamIds);
            }

            if ($memberOrganizationIds->isNotEmpty()) {
                $builder->orWhere(function (Builder $q) use ($memberOrganizationIds): void {
                    $q->whereIn('organization_id', $memberOrganizationIds)
                      ->whereNull('team_id');
                });
            }

            $builder->orWhere(function (Builder $legacy) use ($user): void {
                $legacy->where('user_id', $user->id)
                    ->whereNull('organization_id')
                    ->whereNull('team_id');
            });
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

    public function attachments(): HasMany
    {
        return $this->hasMany(IssueAttachment::class, 'issue_id');
    }

    public static function normalizeType(?string $type): ?string
    {
        return match ($type) {
            self::TYPE_DEVELOPMENT, 'bug' => self::TYPE_DEVELOPMENT,
            self::TYPE_ORGANIZATION, 'task' => self::TYPE_ORGANIZATION,
            default => null,
        };
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
