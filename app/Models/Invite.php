<?php

namespace App\Models;

use App\Enums\InviteStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invite extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'team_id',
        'invited_by_id',
        'email',
        'token',
        'status',
        'expires_at',
        'accepted_at',
    ];

    protected $casts = [
        'status' => InviteStatus::class,
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    /**
     * Temporary plain token (not saved to database).
     */
    public $plain_token;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    public function isExpired(): bool
    {
        return now()->isAfter($this->expires_at);
    }

    public function isPending(): bool
    {
        return $this->status === InviteStatus::PENDING;
    }

    public function isAccepted(): bool
    {
        return $this->status === InviteStatus::ACCEPTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === InviteStatus::CANCELLED;
    }

    public function markAsAccepted(): void
    {
        $this->update([
            'status' => InviteStatus::ACCEPTED,
            'accepted_at' => now(),
        ]);
    }

    public function markAsCancelled(): void
    {
        $this->update([
            'status' => InviteStatus::CANCELLED,
        ]);
    }

    public function markAsExpired(): void
    {
        $this->update([
            'status' => InviteStatus::EXPIRED,
        ]);
    }

    public function scopePending($query)
    {
        return $query->where('status', InviteStatus::PENDING);
    }

    public function scopeValid($query)
    {
        return $query->where('status', InviteStatus::PENDING)
            ->where('expires_at', '>', now());
    }
}
