<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }

    public function methodologies(): HasMany
    {
        return $this->hasMany(Methodology::class);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->withPivot('role');
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)
            ->withPivot('created_at');
    }

    public function followups(): HasMany
    {
        return $this->hasMany(Followup::class);
    }

    public function roleInOrganization(int|Organization $organization): ?string
    {
        return $this->organizations()
            ->find($organization instanceof Organization ? $organization->id : $organization)
            ?->pivot
            ?->role;
    }

    public function isOrganizationMember(int|Organization $organization): bool
    {
        return $this->roleInOrganization($organization instanceof Organization ? $organization->id : $organization) !== null;
    }

    public function isOrganizationManager(int|Organization $organization): bool
    {
        return $this->roleInOrganization($organization instanceof Organization ? $organization->id : $organization) === UserRole::MANAGER->value;
    }

    public function isTeamMember(int|Team $team): bool
    {
        return ((bool)$this->teams()->find($team instanceof Team ? $team->id : $team) ?? false) || $this->isOrganizationManager($team->organization_id);
    }

    /**
     * @param Team[]|int[] $teams
     * @return bool
     */
    public function isMemberOfOneTeam(Collection|array $teams): bool
    {
        foreach ($teams as $team) {
            if ($this->isTeamMember($team)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the user has verified their email address.
     */
    public function hasVerifiedEmail(): bool
    {
        return !is_null($this->email_verified_at);
    }

    /**
     * Mark the user's email as verified.
     */
    public function markEmailAsVerified(): bool
    {
        return $this->forceFill([
            'email_verified_at' => $this->freshTimestamp(),
        ])->save();
    }
}
