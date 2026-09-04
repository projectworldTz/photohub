<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'avatar_path',
        'is_super_admin',
        'is_active',
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
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class)->using(BusinessUser::class)->withPivot(['id', 'employee_number', 'job_title', 'joined_at', 'status'])->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BusinessUser::class);
    }

    public function membershipFor(int $businessId): ?BusinessUser
    {
        return $this->memberships()->with('roles.permissions')->where('business_id', $businessId)->where('status', 'active')->first();
    }

    public function hasPermission(string $permission, ?int $businessId = null): bool
    {
        if ($this->is_super_admin) {
            return true;
        }
        $businessId ??= session('business_id');
        if (! $businessId) {
            return false;
        }
        $membership = $this->membershipFor($businessId);

        return $membership?->roles->contains(fn (Role $role) => $role->slug === 'owner' || $role->permissions->contains('slug', $permission)) ?? false;
    }
}
