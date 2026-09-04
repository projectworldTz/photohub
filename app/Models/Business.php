<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Business extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'slug', 'email', 'phone', 'address', 'city', 'country', 'logo_path', 'description', 'category', 'currency', 'timezone', 'status', 'trial_ends_at'];

    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(BusinessUser::class)->withPivot(['id', 'employee_number', 'job_title', 'joined_at', 'status'])->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BusinessUser::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
