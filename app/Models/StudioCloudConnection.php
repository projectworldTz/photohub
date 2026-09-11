<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudioCloudConnection extends Model
{
    protected $guarded = [];
    protected $hidden = ['api_token', 'registration_secret'];

    protected function casts(): array
    {
        return ['api_token' => 'encrypted', 'registration_secret' => 'encrypted',
            'registered_at' => 'datetime', 'last_connected_at' => 'datetime'];
    }
}
