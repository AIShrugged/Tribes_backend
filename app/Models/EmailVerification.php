<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'email',
        'token',
        'expires_at',
        'verified_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    /**
     * Attributes that should not be saved to the database.
     */
    protected $appends = [];

    /**
     * Temporary plain token (not saved to database).
     */
    public $plain_token;

    /**
     * Get the user that owns the email verification.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if the token has expired.
     */
    public function isExpired(): bool
    {
        return now()->isAfter($this->expires_at);
    }

    /**
     * Check if the verification has been completed.
     */
    public function isVerified(): bool
    {
        return !is_null($this->verified_at);
    }

    /**
     * Mark the verification as verified.
     */
    public function markAsVerified(): void
    {
        $this->update([
            'verified_at' => now(),
        ]);
    }

    /**
     * Scope a query to only include valid (non-expired, non-verified) verifications.
     */
    public function scopeValid($query)
    {
        return $query->whereNull('verified_at')
            ->where('expires_at', '>', now());
    }
}
