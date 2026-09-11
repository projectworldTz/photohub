<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncJob extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['explicit_requested_at' => 'datetime', 'queued_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'next_retry_at' => 'datetime'];
    }
}
