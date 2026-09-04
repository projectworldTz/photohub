<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = ['name', 'slug', 'is_system'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(BusinessUser::class, 'business_user_role', 'role_id', 'business_user_id');
    }
}
