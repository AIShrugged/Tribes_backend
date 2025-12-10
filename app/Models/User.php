<?php

namespace App\Models;

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

    public function activeMethodology(): HasOneThrough
    {
        return $this->hasOneThrough(
            Methodology::class,
            UserMethodology::class,
            'user_id',
            'id',
            'id',
            'methodology_id'
        );
    }

    public function activeMethodologyOrDefault(): Methodology
    {
        return $this->activeMethodology ?? Methodology::where('is_default', true)->firstOrFail();
    }
}
