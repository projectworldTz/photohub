<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class BusinessSetting extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
