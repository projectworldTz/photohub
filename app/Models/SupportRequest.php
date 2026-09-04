<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class SupportRequest extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'user_id', 'subject', 'message', 'status'];
}
