<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PortfolioCategory extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'name', 'slug'];

    public function photos(): BelongsToMany
    {
        return $this->belongsToMany(Photo::class, 'portfolio_photos')->withPivot('position')->orderByPivot('position');
    }
}
