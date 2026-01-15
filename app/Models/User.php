<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use Notifiable, HasApiTokens;

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
        return $this->belongsToMany(Team::class);
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
        return ((bool) $this->teams()->find($team instanceof Team ? $team->id : $team) ?? false) || $this->isOrganizationManager($team->organization);
    }
}
