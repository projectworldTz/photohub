<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = ['name', 'slug', 'price', 'storage_limit_mb', 'gallery_limit', 'is_active'];
}
