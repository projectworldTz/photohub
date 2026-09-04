<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class ContractTemplate extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'name', 'body', 'is_active'];
}
